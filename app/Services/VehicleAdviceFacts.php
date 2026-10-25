<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Refueling;
use App\Models\Trip;
use App\Models\TripWaypoint;
use App\Models\Vehicle;
use App\Models\VehicleTip;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Locale;
use Throwable;

/**
 * The facts the general advice of a vehicle is based on: everything the app
 * knows about it, calculated rather than left to the AI model. Days are given
 * as dates rather than as days from today, so that the facts only change when
 * something about the vehicle does (see fingerprint()).
 */
class VehicleAdviceFacts
{
    /** Maintenances the model gets to see, the most recent first. */
    private const MAINTENANCES = 15;

    /** Refuelings the recent fuel consumption is the average of. */
    private const RECENT_REFUELINGS = 5;

    /** Refuelings before those, the earlier fuel consumption is the average of. */
    private const EARLIER_REFUELINGS = 15;

    /** Years of accidents the model gets to see. */
    private const ACCIDENT_YEARS = 3;

    /** Characters of a description the model gets to see, at most. */
    private const DESCRIPTION = 200;

    /** Days ahead the planned trips are of. */
    private const TRIPS_WITHIN = 60;

    /** Days of the weather forecast. */
    private const FORECAST_DAYS = 14;

    /** Degrees from which a day is a hot one: the air conditioning, the cooling. */
    private const HOT = 30;

    /** Days after which a recorded mileage is estimated for today. */
    private const MILEAGE_ESTIMATED_AFTER = 30;

    /** The kilometers an estimated mileage is rounded to: it should not change the facts by the day. */
    private const MILEAGE_ROUNDED_TO = 500;

    /** The RDW data that says something about the vehicle, by the name the model gets to see. */
    private const RDW = [
        'body' => 'inrichting',
        'cylinders' => 'aantal_cilinders',
        'engine_capacity_cc' => 'cilinderinhoud',
        'curb_weight_kg' => 'massa_rijklaar',
        'catalog_price_eur' => 'catalogusprijs',
        'first_registration' => 'datum_eerste_toelating_dt',
        'odometer_judgement' => 'tellerstandoordeel',
        'odometer_last_registered_year' => 'jaar_laatste_registratie_tellerstand',
        'energy_label' => 'zuinigheidsclassificatie',
        'insured' => 'wam_verzekerd',
        'mot_expiry_date' => 'vervaldatum_apk_dt',
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(Vehicle $vehicle): array
    {
        $vehicle->loadMissing(['maintenances', 'reconditionings', 'insurances', 'taxes', 'refuelings', 'user']);

        return [
            'today' => today()->toDateString(),
            'month' => today()->englishMonth,
            'vehicle' => $this->vehicle($vehicle),
            'rdw' => $this->rdw($vehicle),
            'usage' => $usage = $this->usage($vehicle),
            'mileage' => $this->mileage($vehicle, $usage['average_km_per_month_past_year'] ?? null),
            'planned_trips' => $this->trips($vehicle),
            'weather_forecast' => $this->weather($vehicle),
            'next_maintenances' => $this->nextMaintenances($vehicle),
            'maintenance_plan' => $this->maintenancePlan($vehicle),
            'maintenance_history' => $this->maintenances($vehicle),
            'latest_reconditioning_by_type' => $vehicle->reconditionings
                ->sortByDesc('date')
                ->unique(fn ($reconditioning) => self::scalar($reconditioning->type))
                ->mapWithKeys(fn ($reconditioning) => [(string) self::scalar($reconditioning->type) => $reconditioning->date->toDateString()])
                ->all(),
            'insurance' => $this->insurance($vehicle),
            'road_tax_paid_until' => $vehicle->taxes->where('start_date', '<=', today())->sortByDesc('start_date')->first()?->end_date?->toDateString(),
            'open_findings' => app(FindingService::class)->facts($vehicle),
            'top_ups' => app(TopUps::class)->facts($vehicle),
            'accidents' => $this->accidents($vehicle),
            'valid_vignettes_and_stickers' => $this->vignettes($vehicle),
            'average_monthly_costs_past_year_eur' => $this->costs($vehicle),
            'already_shown_notifications' => $this->notifications($vehicle),
            'handled_suggestions' => VehicleTip::query()
                ->where('vehicle_id', $vehicle->id)
                ->orderBy('updated_at')
                ->get()
                ->map(fn (VehicleTip $tip) => [
                    'key' => $tip->key,
                    'status' => $tip->status,
                    'title' => $tip->title,
                    'since' => $tip->updated_at->toDateString(),
                ])
                ->all(),
            'previous_suggestions' => $this->previous($vehicle),
        ];
    }

    /**
     * What tells whether the facts changed, apart from the day they are of
     * and the advice itself. Of the weather only whether frost, snow or heat
     * is coming: the forecast changes by the hour, that by the season.
     *
     * @param  array<string, mixed>  $facts
     */
    public function fingerprint(array $facts): string
    {
        unset($facts['today'], $facts['month'], $facts['previous_suggestions']);

        if (isset($facts['weather_forecast'])) {
            $facts['weather_forecast'] = array_map(
                fn ($date) => $date !== null,
                Arr::only($facts['weather_forecast'], ['first_frost_date', 'first_snow_date', 'first_hot_day_date']),
            );
        }

        return hash('sha256', (string) json_encode($facts));
    }

    /**
     * The tips of the latest advice the owner did nothing with yet, so that
     * the model keeps their keys rather than making them up anew each time.
     *
     * @return list<array{key: string, title: string}>
     */
    private function previous(Vehicle $vehicle): array
    {
        $handled = VehicleTip::query()->where('vehicle_id', $vehicle->id)->pluck('key')->all();

        return collect($vehicle->vehicleAdvices()->latest()->first()->suggestions ?? [])
            ->reject(fn (array $suggestion) => in_array($suggestion['key'] ?? null, $handled, true))
            ->map(fn (array $suggestion) => ['key' => (string) $suggestion['key'], 'title' => (string) ($suggestion['title'] ?? '')])
            ->values()
            ->all();
    }

    /**
     * When the mileage was recorded last and, when that is a while ago, an
     * estimate of today's. Null when no record tells.
     *
     * @return array<string, mixed>|null
     */
    private function mileage(Vehicle $vehicle, ?int $perMonth): ?array
    {
        $latest = collect([
            ...$vehicle->refuelings->map(fn (Refueling $refueling) => ['km' => (int) $refueling->mileage_end, 'date' => $refueling->date]),
            ...$vehicle->maintenances->map(fn (Maintenance $maintenance) => ['km' => (int) $maintenance->mileage, 'date' => $maintenance->date]),
        ])
            ->filter(fn (array $reading) => $reading['km'] > 0)
            ->sortByDesc(fn (array $reading) => $reading['date']->toDateString())
            ->first();

        if ($latest === null) {
            return null;
        }

        $km = max($latest['km'], (int) $vehicle->mileage_latest);
        $days = (int) $latest['date']->diffInDays(today());

        return array_filter([
            'latest_recorded_on' => $latest['date']->toDateString(),
            'estimated_today_km' => $perMonth && $days >= self::MILEAGE_ESTIMATED_AFTER
                ? (int) (round(($km + $perMonth * $days / 30.44) / self::MILEAGE_ROUNDED_TO) * self::MILEAGE_ROUNDED_TO)
                : null,
        ]);
    }

    /**
     * The trips planned with the vehicle in the coming weeks: what it should
     * be ready for. The trip planner gives the travel tips themselves.
     *
     * @return list<array<string, mixed>>
     */
    private function trips(Vehicle $vehicle): array
    {
        $country = fn (string $code) => extension_loaded('intl') ? (Locale::getDisplayRegion('-'.$code, 'en') ?: $code) : $code;

        return Trip::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [Trip::STATUS_ROUTED, Trip::STATUS_PLANNED])
            ->whereBetween('time_at', [today(), today()->addDays(self::TRIPS_WITHIN)->endOfDay()])
            ->with('waypoints')
            ->orderBy('time_at')
            ->get()
            ->map(function (Trip $trip) use ($country) {
                $route = $trip->plan['routes'][$trip->selected_route] ?? $trip->plan['routes'][0] ?? [];

                return array_filter([
                    'departure_date' => Carbon::parse($route['departure_at'] ?? $trip->time_at)->toDateString(),
                    'destinations' => $trip->waypoints->skip(1)->map(fn (TripWaypoint $waypoint) => trim(explode(',', $waypoint->label)[0]))->values()->all(),
                    'distance_km' => isset($route['distance']) ? (int) round($route['distance'] / 1000) : null,
                    'countries' => array_map($country, $route['countries'] ?? []),
                    'vignette_countries' => array_map($country, $route['vignettes'] ?? []),
                ], fn ($value) => $value !== null && $value !== []);
            })
            ->values()
            ->all();
    }

    /**
     * What the weather of the coming weeks asks for where the driver lives:
     * the first frost, snow and heat. Null when the place, or the forecast,
     * is not known.
     *
     * @return array<string, mixed>|null
     */
    private function weather(Vehicle $vehicle): ?array
    {
        $user = $vehicle->user;

        if ($user?->latitude === null || $user->longitude === null) {
            return null;
        }

        $days = collect(app(OpenMeteoService::class)->forecast($user->latitude, $user->longitude, self::FORECAST_DAYS));

        if ($days->isEmpty()) {
            return null;
        }

        $frost = $days->filter(fn (array $day) => $day['min'] <= 0);

        return [
            'days' => $days->count(),
            'lowest_temperature_c' => (int) round($days->min('min')),
            'highest_temperature_c' => (int) round($days->max('max')),
            'first_frost_date' => $frost->first()['date'] ?? null,
            'frost_days' => $frost->count(),
            'first_snow_date' => $days->first(fn (array $day) => $day['snow_cm'] > 0)['date'] ?? null,
            'first_hot_day_date' => $days->first(fn (array $day) => $day['max'] >= self::HOT)['date'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicle(Vehicle $vehicle): array
    {
        return array_filter([
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'version' => $vehicle->version,
            'engine' => $vehicle->engine,
            'powertrain' => $vehicle->powertrain,
            'fuel_types' => $vehicle->fuel_types ?: null,
            'tank_capacity_liters' => $vehicle->tank_capacity ?: null,
            'construction_date' => $vehicle->construction_date?->toDateString(),
            'age_years' => $vehicle->construction_date ? (int) $vehicle->construction_date->diffInYears(today()) : null,
            'purchase_date' => $vehicle->purchase_date?->toDateString(),
            'purchase_price_eur' => $vehicle->purchase_price ?: null,
            'mileage_at_purchase_km' => $vehicle->mileage_start ?: null,
            'mileage_km' => $vehicle->mileage_latest ?: null,
            'country_of_registration' => $vehicle->country_registration,
            'status' => $vehicle->status,
            'maintenance_intervals' => $vehicle->maintenanceIntervals() ?: null,
            'driver_city' => $vehicle->user?->city,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * What the RDW (the Dutch vehicle authority) knows; null for a vehicle it does not.
     *
     * @return array<string, mixed>|null
     */
    private function rdw(Vehicle $vehicle): ?array
    {
        $data = (array) $vehicle->rdw_data;

        if ($data === []) {
            return null;
        }

        $fuel = collect((array) ($data['fuel'] ?? []))->firstWhere('brandstof_volgnummer', '1') ?? [];
        $inspection = $data['inspection'] ?? null;

        return array_filter([
            ...collect(self::RDW)->map(fn (string $key) => $data[$key] ?? null)->all(),
            'emission_class' => $fuel['uitlaatemissieniveau'] ?? null,
            'power_kw' => $fuel['nettomaximumvermogen'] ?? null,
            'open_recalls' => collect((array) ($data['open_recalls'] ?? []))
                ->map(fn ($recall) => is_array($recall) ? Str::limit((string) ($recall['description'] ?? $recall['omschrijving_defect'] ?? json_encode($recall)), self::DESCRIPTION) : (string) $recall)
                ->values()
                ->all(),
            'latest_inspection' => empty($inspection['date']) ? null : [
                'date' => Carbon::parse($inspection['date'])->toDateString(),
                'type' => $inspection['type'] ?? null,
                'defects' => collect((array) ($inspection['defects'] ?? []))->pluck('description')->filter()->values()->all(),
            ],
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * How the vehicle is driven, from its refuelings.
     *
     * @return array<string, mixed>|null
     */
    private function usage(Vehicle $vehicle): ?array
    {
        $refuelings = $vehicle->refuelings->sortByDesc(fn (Refueling $refueling) => $refueling->date->toDateString().' '.$refueling->created_at)->values();

        if ($refuelings->isEmpty()) {
            return null;
        }

        $pastYear = $refuelings->filter(fn (Refueling $refueling) => $refueling->date->gte(today()->subYear()));
        $first = $pastYear->last();
        $months = $first ? max(1, (int) $first->date->diffInMonths(today())) : null;
        $distance = $pastYear->sum(fn (Refueling $refueling) => max(0, (int) $refueling->mileage_end - (int) $refueling->mileage_begin));
        $consumption = fn ($refuelings) => ($average = $refuelings->pluck('fuel_consumption')->filter(fn ($value) => (float) $value > 0)->avg()) ? round((float) $average, 2) : null;

        return array_filter([
            'average_km_per_month_past_year' => $months && $distance > 0 ? (int) round($distance / $months) : null,
            'fuel_consumption_recent' => $consumption($refuelings->take(self::RECENT_REFUELINGS)),
            'fuel_consumption_before' => $consumption($refuelings->slice(self::RECENT_REFUELINGS, self::EARLIER_REFUELINGS)),
            'consumption_unit' => trans('powertrains')[$vehicle->powertrain]['consumption_unit'] ?? null,
            'latest_refueling_date' => $refuelings->first()->date->toDateString(),
            'tires_at_latest_refueling' => self::scalar($refuelings->first()->tires),
            'usual_driving_style' => $this->mostCommon($refuelings->take(self::EARLIER_REFUELINGS), 'driving_style'),
            'usual_routes' => $this->mostCommon($refuelings->take(self::EARLIER_REFUELINGS), 'routes'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The value of an enum, or the value itself: not every column is cast.
     */
    private static function scalar(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function mostCommon($refuelings, string $attribute): ?string
    {
        $value = $refuelings
            ->map(fn (Refueling $refueling) => $refueling->{$attribute})
            ->map(fn ($value) => is_array($value) ? implode(',', $value) : self::scalar($value))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return $value === null ? null : (string) $value;
    }

    /**
     * Per maintenance interval when it is due. The date only by time: one by
     * distance moves by the day, and the facts should not.
     *
     * @return list<array<string, mixed>>
     */
    private function nextMaintenances(Vehicle $vehicle): array
    {
        return array_map(fn (array $next) => array_filter([
            'maintenance_types' => $next['types'],
            'last_done' => $next['maintenance']->date->toDateString(),
            'due_date' => $next['months'] !== null ? $next['date']->toDateString() : null,
            'due_at_km' => $next['dueKm'],
        ]), $vehicle->nextMaintenances());
    }

    /**
     * The components the maintenance plan of the app tracks. Without how
     * urgent they are today: that changes by the day, the plan does not.
     *
     * @return list<array<string, mixed>>
     */
    private function maintenancePlan(Vehicle $vehicle): array
    {
        return collect(app(MaintenancePlanService::class)->plan($vehicle))
            ->map(fn (array $item) => array_filter([
                'component' => $item['key'],
                'interval_km' => $item['interval_km'],
                'interval_months' => $item['interval_months'],
                'last_done' => $item['done']['date'] ?? null,
                'last_done_km' => $item['done']['km'] ?? null,
                // Never recorded: the next one counted from new, an assumption.
                'last_done_not_known' => $item['assumed_since'] !== null ? true : null,
                'next_due' => $item['due_date'],
                'next_due_km' => $item['due_km'],
            ], fn ($value) => $value !== null))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maintenances(Vehicle $vehicle): array
    {
        return $vehicle->maintenances
            ->sortByDesc('date')
            ->take(self::MAINTENANCES)
            ->map(fn (Maintenance $maintenance) => array_filter([
                'date' => $maintenance->date->toDateString(),
                'mileage_km' => $maintenance->mileage ?: null,
                'type' => self::scalar($maintenance->type_maintenance),
                'mot' => $maintenance->apk ?: null,
                'airco_check' => $maintenance->airco_check ?: null,
                'garage' => $maintenance->garage ?: null,
                'description' => filled($maintenance->description) ? Str::limit(strip_tags((string) $maintenance->description), self::DESCRIPTION) : null,
                'tasks' => collect($maintenance->tasks ?? [])->pluck('task')->filter()->values()->all() ?: null,
                'total_price_eur' => $maintenance->total_price ?: null,
            ], fn ($value) => $value !== null && $value !== ''))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function insurance(Vehicle $vehicle): ?array
    {
        $insurance = $vehicle->insurances->where('start_date', '<=', today())->sortByDesc('start_date')->first();

        if ($insurance === null) {
            return null;
        }

        return array_filter([
            'company' => $insurance->insurance_company,
            'type' => self::scalar($insurance->type),
            'price_eur' => $insurance->price ?: null,
            'start_date' => $insurance->start_date?->toDateString(),
            'end_date' => $insurance->end_date?->toDateString(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accidents(Vehicle $vehicle): array
    {
        return $vehicle->accidents()
            ->where('datetime', '>=', today()->subYears(self::ACCIDENT_YEARS))
            ->orderByDesc('datetime')
            ->get()
            ->map(fn ($accident) => array_filter([
                'date' => Carbon::parse($accident->datetime)->toDateString(),
                'type' => self::scalar($accident->type),
                'description' => filled($accident->description) ? Str::limit(strip_tags((string) $accident->description), self::DESCRIPTION) : null,
            ], fn ($value) => $value !== null && $value !== ''))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vignettes(Vehicle $vehicle): array
    {
        $valid = fn ($query) => $query->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->get();

        return collect([
            ...$valid($vehicle->vignettes())->map(fn ($vignette) => ['kind' => 'vignette', 'country' => $vignette->country, 'valid_until' => $vignette->end_date?->toDateString()]),
            ...$valid($vehicle->environmentalStickers())->map(fn ($sticker) => ['kind' => 'environmental_sticker', 'country' => $sticker->country, 'valid_until' => $sticker->end_date?->toDateString()]),
        ])->values()->all();
    }

    /**
     * @return array<string, float>
     */
    private function costs(Vehicle $vehicle): array
    {
        return rescue(
            fn () => collect($vehicle->calculateAverageMonthlyCostsByType(today()->subYear()->toDateString(), today()->toDateString()))
                ->filter(fn ($amount) => (float) $amount > 0)
                ->map(fn ($amount) => round((float) $amount, 2))
                ->all(),
            [],
            false,
        );
    }

    /**
     * The notifications the dashboard already shows, so that the advice does
     * not repeat them. In English: what the model reads should not depend on
     * the language of the user, nor change the fingerprint.
     *
     * @return list<array{key: string, type: string, text: string}>
     */
    private function notifications(Vehicle $vehicle): array
    {
        $locale = App::getLocale();
        App::setLocale('en');

        try {
            $notifications = (new VehicleStatusService)->getNotifications($vehicle);
        } catch (Throwable $exception) {
            // Some statuses consult external services; the advice does without them.
            Log::warning('The notifications could not be added to the facts of the vehicle advice', [
                'vehicle' => $vehicle->id,
                'message' => $exception->getMessage(),
            ]);

            return [];
        } finally {
            App::setLocale($locale);
        }

        return collect($notifications)
            ->reject(fn (array $notification) => $notification['type'] === 'success')
            ->map(fn (array $notification) => [
                'key' => $notification['key'],
                'type' => $notification['type'],
                'text' => $notification['text'],
            ])
            ->values()
            ->all();
    }
}
