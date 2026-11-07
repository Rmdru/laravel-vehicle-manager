<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;
use App\Enums\AccidentType;
use App\Enums\MaintenanceTypeMaintenance;
use App\Enums\ParkingType;
use App\Enums\ReconditioningExecutor;
use App\Enums\ReconditioningType;
use App\Enums\ServiceType;
use App\Models\Accident;
use App\Models\EnvironmentalSticker;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\Product;
use App\Models\Reconditioning;
use App\Models\Tax;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Vignette;
use App\Support\GasStationLogo;
use App\Support\Roads;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Everything that happened to (and is expected for) a vehicle, grouped by month.
 */
class TimelineService
{
    /** Months ahead the predictions look. */
    private const PREDICTION_MONTHS = 12;

    /** The navigation group a record belongs to, which the timeline (and the latest costs) colors it by. */
    public const GROUPS = [
        Maintenance::class => 'maintenance',
        Reconditioning::class => 'maintenance',
        Accident::class => 'maintenance',
        Product::class => 'maintenance',
        Insurance::class => 'fixed',
        Tax::class => 'fixed',
        Vignette::class => 'fixed',
        EnvironmentalSticker::class => 'fixed',
        // The vehicle itself stands for its MOT.
        Vehicle::class => 'maintenance',
    ];

    /**
     * @return list<array{month: string, items: list<array<string, mixed>>}>
     */
    public function history(Vehicle $vehicle): array
    {
        $insuranceTypes = config('insurances.types');
        $fuelTypes = trans('fuel_types');

        $vehicle->load([
            'maintenances' => fn ($query) => $query->where(fn ($query) => collect(Vehicle::MAINTENANCE_TYPES)->each(fn (string $type) => $query->orWhereJsonContains('type_maintenance', $type))),
            'refuelings', 'insurances', 'taxes', 'parkings', 'tolls', 'fines', 'reconditionings',
            'vignettes', 'environmentalStickers', 'ferries', 'products', 'services', 'accidents',
        ]);

        $items = collect();

        foreach ($vehicle->maintenances as $maintenance) {
            $items->push($this->item($maintenance, __('Maintenance'), empty($maintenance->type_maintenance) && $maintenance->apk ? 'ic:baseline-security' : 'mdi:car-wrench', 'maintenances', $maintenance->date, [
                'price' => $maintenance->total_price,
                'location' => $maintenance->garage,
                'badges' => array_values(array_filter([
                    ...array_map(fn (string $type) => $this->badge(MaintenanceTypeMaintenance::tryFrom($type)?->getLabel(), icon: 'mdi:car-wrench'), $maintenance->type_maintenance),
                    $maintenance->apk ? $this->badge(__('MOT'), icon: 'ic:baseline-security') : null,
                ])),
            ]));
        }

        foreach ($vehicle->refuelings as $refueling) {
            $items->push($this->item($refueling, __('Refueling'), 'ic:round-local-gas-station', 'refuelings', $refueling->date, [
                'logo' => GasStationLogo::url($refueling->gas_station),
                'price' => round((float) $refueling->total_price, 2),
                'mileage' => $refueling->mileage_end && $refueling->mileage_begin ? $refueling->mileage_end - $refueling->mileage_begin : null,
                'location' => $refueling->gas_station,
                'badges' => [$this->badge($fuelTypes[$refueling->fuel_type] ?? $refueling->fuel_type)],
            ]));
        }

        // Insurances can overlap: every month counts once, for the insurance that was active.
        $vehicle->insurances
            ->flatMap(fn (Insurance $insurance) => $insurance->months)
            ->unique()
            ->each(function (string $month) use ($vehicle, $items, $insuranceTypes) {
                $insurance = Insurance::getActiveMonthlyRecordForMonth($vehicle->insurances, Carbon::parse($month.'-01'));

                if (! $insurance) {
                    return;
                }

                $items->push($this->item($insurance, __('Insurance'), 'mdi:shield-car', 'insurances', $this->invoiceDate($month, $insurance->invoice_day), [
                    'price' => $insurance->price,
                    'badges' => [$this->badge($insuranceTypes[$insurance->type]['name'] ?? null, icon: $insuranceTypes[$insurance->type]['icon'] ?? null)],
                ]));
            });

        foreach ($vehicle->taxes as $tax) {
            foreach ($tax->months as $month) {
                $items->push($this->item($tax, __('Road tax'), 'mdi:highway', 'taxes', $this->invoiceDate($month, $tax->invoice_day), [
                    'price' => $tax->price,
                ]));
            }
        }

        foreach ($vehicle->parkings as $parking) {
            $items->push($this->item($parking, __('Parking'), 'fa7-solid:square-parking', 'parking', $parking->end_time, [
                'price' => $parking->price,
                'location' => $parking->location,
                'badges' => [$this->enumBadge(ParkingType::tryFrom((string) $parking->type))],
            ]));
        }

        foreach ($vehicle->tolls as $toll) {
            $hasRoad = filled($toll->road_type) && filled($toll->road) && filled($toll->country);

            $items->push($this->item($toll, __('Toll'), 'mdi:boom-gate', 'toll', $toll->start_date, [
                'price' => $toll->price,
                'location' => filled($toll->end_location) ? $toll->start_location.' - '.$toll->end_location : $toll->start_location,
                'country' => $hasRoad ? $toll->country : null,
                'roads' => $hasRoad ? Roads::badges($toll->country, $toll->road_type, $toll->road) : [],
            ]));
        }

        foreach ($vehicle->fines as $fine) {
            $items->push($this->item($fine, __('Fine'), 'maki:police', 'fines', $fine->date, [
                'price' => $fine->price,
                'location' => $fine->location,
                'badges' => [$fine->payed ? $this->badge(__('Payed'), 'success', 'ic:round-check') : $this->badge(__('Pending payment'), 'danger', 'ic:sharp-timer')],
            ]));
        }

        foreach ($vehicle->reconditionings as $reconditioning) {
            $heading = ReconditioningType::tryFrom((string) $reconditioning->type)?->getLabel() ?? __('Reconditioning');

            $items->push($this->item($reconditioning, $heading, 'mdi:car-wash', 'reconditionings', $reconditioning->date, [
                'price' => $reconditioning->price,
                'location' => $reconditioning->location,
                'badges' => [$this->badge(ReconditioningExecutor::tryFrom((string) $reconditioning->executor)?->getLabel())],
            ]));
        }

        foreach ($vehicle->vignettes as $vignette) {
            $items->push($this->item($vignette, __('Vignette'), 'mdi:sticker-text', 'vignettes', $vignette->start_date, [
                'price' => $vignette->price,
                'country' => $vignette->country,
            ]));
        }

        foreach ($vehicle->environmentalStickers as $sticker) {
            $items->push($this->item($sticker, __('Environmental sticker'), 'fa7-solid:leaf', 'environmental-stickers', $sticker->start_date, [
                'price' => $sticker->price,
                'country' => $sticker->country,
            ]));
        }

        foreach ($vehicle->ferries as $ferry) {
            $items->push($this->item($ferry, __('Transport train & ferry service'), 'mdi:train-car-flatbed-car', 'ferries', $ferry->start_date, [
                'price' => $ferry->price,
                'location' => $ferry->start_location.' - '.$ferry->end_location,
            ]));
        }

        foreach ($vehicle->products as $product) {
            $items->push($this->item($product, (string) $product->name, 'mdi:oil', 'products', $product->date, [
                'price' => $product->price,
            ]));
        }

        foreach ($vehicle->services as $service) {
            $heading = ServiceType::tryFrom((string) $service->type)?->getLabel() ?? __('Service');

            $items->push($this->item($service, $heading, ServiceType::tryFrom((string) $service->type)?->getIcon() ?? 'mdi:tow-truck', 'services', $service->date, [
                'price' => $service->price,
                'location' => $service->name,
            ]));
        }

        foreach ($vehicle->accidents as $accident) {
            $items->push($this->item($accident, __('Accident'), 'fa7-solid:car-burst', 'accidents', $accident->datetime, [
                'price' => $accident->total_price,
                'location' => $accident->location,
                'badges' => [$this->enumBadge(AccidentType::tryFrom((string) $accident->type))],
            ]));
        }

        return $this->groupByMonth($items);
    }

    /**
     * Upcoming maintenance and invoices.
     *
     * @return list<array{month: string, items: list<array<string, mixed>>}>
     */
    public function predictions(Vehicle $vehicle): array
    {
        $insuranceTypes = config('insurances.types');
        $items = collect();

        // The history only loaded the maintenance of the maintenance types; the MOT needs all of it.
        $vehicle->load('maintenances');

        $nextMaintenance = $vehicle->nextMaintenance();
        // When the next maintenance is expected: what is on the list for the garage goes along then.
        $nextMaintenanceDate = null;

        if ($nextMaintenance) {
            $lastMaintenance = $nextMaintenance['maintenance'];

            // Whichever comes first: the interval in time or the one in distance.
            $date = $nextMaintenance['daysTillDistance'] === null
                ? $nextMaintenance['date']
                : $nextMaintenance['date']->min(now()->addDays($nextMaintenance['daysTillDistance']));

            if ($date->isFuture()) {
                $nextMaintenanceDate = Carbon::parse($date);
                $items->push($this->item($lastMaintenance, $nextMaintenance['label'], 'mdi:car-wrench', null, $date, [
                    'location' => $lastMaintenance->garage,
                    'badges' => array_map(fn (string $type) => $this->badge(MaintenanceTypeMaintenance::from($type)->getLabel(), icon: 'mdi:car-wrench'), $lastMaintenance->type_maintenance),
                ]));
            }
        }

        $apkDate = $vehicle->apkExpiryDate();

        if ($apkDate?->isFuture()) {
            $items->push($this->item($vehicle, __('MOT'), 'ic:baseline-security', null, $apkDate, [
                'badges' => [$this->badge(__('Expires'), 'warning', 'ic:sharp-timer')],
            ]));
        }

        foreach ($vehicle->vignettes->concat($vehicle->environmentalStickers) as $sticker) {
            if ($sticker->end_date?->isFuture()) {
                $isVignette = $sticker instanceof Vignette;

                $items->push($this->item($sticker, $isVignette ? __('Vignette') : __('Environmental sticker'), $isVignette ? 'mdi:sticker-text' : 'fa7-solid:leaf', null, $sticker->end_date, [
                    'country' => $sticker->country,
                    'badges' => [$this->badge(__('Expires'), 'warning', 'ic:sharp-timer')],
                ]));
            }
        }

        $until = today()->addMonths(self::PREDICTION_MONTHS)->endOfMonth();
        [$fixed, $fixedByMonth] = $this->fixedCosts($vehicle, $until, $insuranceTypes);

        $items->push(
            ...$fixed,
            ...$this->componentsDue($vehicle, $until),
            ...$this->findingsForTheGarage($vehicle, $nextMaintenanceDate),
            ...$this->plannedTrips($vehicle, $until),
            ...$this->insuranceEnds($vehicle, $until),
        );

        $items = $items->filter(fn (array $item) => $item['sort'] <= $until->timestamp)->values();

        // Per month what to expect: the fixed costs of that month, and of the
        // rest what it came to a month on average in the past year.
        $variable = $this->averageVariableCosts($vehicle);

        return array_map(function (array $month) use ($fixedByMonth, $variable) {
            $expected = ($fixedByMonth[$month['month']] ?? 0) + $variable;

            return [...$month, 'expected' => $expected > 0 ? '€ '.number_format(round($expected), 0, ',', '.') : null];
        }, $this->groupByMonth($items));
    }

    /**
     * The insurance and the road tax as one row per month in which they are
     * invoiced: one item for each would fill the predictions with the same.
     *
     * @param  array<string, array<string, mixed>>  $insuranceTypes
     * @return array{0: list<array<string, mixed>>, 1: array<string, float>} The items, and what they come to by month.
     */
    private function fixedCosts(Vehicle $vehicle, Carbon $until, array $insuranceTypes): array
    {
        $rows = [];
        $byMonth = [];

        for ($month = today()->startOfMonth(); $month->lte($until); $month = $month->copy()->addMonth()) {
            $invoices = collect([
                ['record' => Insurance::getActiveMonthlyRecordForMonth($vehicle->insurances, $month), 'label' => __('Insurance')],
                ['record' => Tax::getActiveMonthlyRecordForMonth($vehicle->taxes, $month), 'label' => __('Road tax')],
            ])
                ->filter(fn (array $invoice) => $invoice['record'] !== null && (float) $invoice['record']->price > 0)
                ->map(fn (array $invoice) => [...$invoice, 'date' => $this->invoiceDate($month->format('Y-m'), $invoice['record']->invoice_day)])
                // Invoiced already this month.
                ->filter(fn (array $invoice) => $invoice['date']->gte(today()));

            if ($invoices->isEmpty()) {
                continue;
            }

            $first = $invoices->sortBy('date')->first();
            $amount = (float) $invoices->sum(fn (array $invoice) => (float) $invoice['record']->price);

            $item = $this->item($first['record'], __('Fixed costs'), 'mdi:cash-multiple', null, $first['date'], [
                'price' => $amount,
                'badges' => $invoices->map(fn (array $invoice) => $this->badge(
                    $invoice['record'] instanceof Insurance ? ($insuranceTypes[$invoice['record']->type]['name'] ?? $invoice['label']) : $invoice['label'],
                    icon: $invoice['record'] instanceof Insurance ? ($insuranceTypes[$invoice['record']->type]['icon'] ?? 'mdi:shield-car') : 'mdi:highway',
                ))->values()->all(),
            ]);

            $rows[] = [...$item, 'key' => 'fixed-'.$month->format('Ym')];
            $byMonth[$item['month']] = $amount;
        }

        return [$rows, $byMonth];
    }

    /**
     * The components of the maintenance plan that fall due within the
     * horizon, those due on the same day together. Not the ones overdue or to
     * be checked: those need attention now, and are shown there.
     *
     * @return list<array<string, mixed>>
     */
    private function componentsDue(Vehicle $vehicle, Carbon $until): array
    {
        $components = config('maintenance_plan.components');

        return collect(app(MaintenancePlanService::class)->plan($vehicle))
            ->filter(fn (array $item) => in_array($item['state'], [MaintenancePlanService::DUE, MaintenancePlanService::SOON, MaintenancePlanService::OK], true)
                && $item['due_date'] !== null
                && $item['due_date'] >= today()->toDateString()
                && $item['due_date'] <= $until->toDateString())
            ->groupBy('due_date')
            ->map(function (Collection $due, string $date) use ($vehicle, $components) {
                $labels = $due->map(fn (array $item) => __($components[$item['key']]['label']))->all();
                $item = $this->item($vehicle, implode(', ', $labels), $due->count() === 1 ? $components[$due->first()['key']]['icon'] : 'mdi:clipboard-list-outline', null, $date, [
                    'badges' => [
                        $this->badge(__('Maintenance plan'), icon: 'mdi:clipboard-list-outline'),
                        $due->contains('estimated', true) ? $this->badge(__('Estimated by mileage'), icon: 'mdi:speedometer') : null,
                    ],
                ]);

                return [...$item, 'key' => 'plan-'.$date, 'group' => 'maintenance', 'url' => route('maintenance-plan')];
            })
            ->values()
            ->all();
    }

    /**
     * What is on the list for the garage, at the next maintenance: then it is
     * taken along. Without a next maintenance it needs attention instead.
     *
     * @return list<array<string, mixed>>
     */
    private function findingsForTheGarage(Vehicle $vehicle, ?Carbon $nextMaintenance): array
    {
        if ($nextMaintenance === null) {
            return [];
        }

        return app(FindingService::class)->open($vehicle)
            ->filter(fn ($finding) => $finding->for_garage)
            ->map(fn ($finding) => [
                ...$this->item($finding, $finding->title, 'mdi:garage-alert', null, $nextMaintenance, [
                    'badges' => [$this->badge(__('On the list for the garage'), 'warning', 'mdi:clipboard-check-outline')],
                ]),
                'group' => 'maintenance',
            ])
            ->values()
            ->all();
    }

    /**
     * The trips planned with the vehicle, with what the route is expected to cost.
     *
     * @return list<array<string, mixed>>
     */
    private function plannedTrips(Vehicle $vehicle, Carbon $until): array
    {
        return Trip::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [Trip::STATUS_ROUTED, Trip::STATUS_PLANNED])
            ->whereBetween('time_at', [today(), $until])
            ->with('waypoints')
            ->get()
            ->map(function (Trip $trip) {
                $route = $trip->plan['routes'][$trip->selected_route] ?? $trip->plan['routes'][0] ?? [];
                $destination = $trip->name ?: trim(explode(',', (string) $trip->waypoints->sortBy('position')->last()?->label)[0]);
                $distance = isset($route['distance']) ? (int) round($route['distance'] / 1000) : null;

                return [
                    ...$this->item($trip, $destination ?: __('Trip'), 'mdi:map-marker-path', null, $route['departure_at'] ?? $trip->time_at, [
                        'price' => $route['costs']['total'] ?? null,
                        'badges' => [
                            $this->badge(__('Trip'), icon: 'mdi:map-marker-path'),
                            $distance !== null ? $this->badge($distance.' km', icon: 'ic:baseline-route') : null,
                            isset($route['costs']['total']) ? $this->badge(__('Estimate'), icon: 'mdi:calculator-variant-outline') : null,
                        ],
                    ]),
                    'group' => 'road',
                    'url' => route('trips.show', $trip),
                ];
            })
            ->all();
    }

    /**
     * The end of an insurance: a moment to compare before it is renewed.
     *
     * @return list<array<string, mixed>>
     */
    private function insuranceEnds(Vehicle $vehicle, Carbon $until): array
    {
        return $vehicle->insurances
            ->filter(fn (Insurance $insurance) => $insurance->end_date !== null && $insurance->end_date->isFuture() && $insurance->end_date->lte($until))
            ->map(fn (Insurance $insurance) => [
                ...$this->item($insurance, __('Insurance ends'), 'mdi:shield-car', null, $insurance->end_date, [
                    'location' => $insurance->insurance_company,
                    'badges' => [$this->badge(__('Compare before it is renewed'), 'info', 'mdi:scale-balance')],
                ]),
                'key' => $insurance->getKey().'-end',
            ])
            ->values()
            ->all();
    }

    /**
     * What the costs other than the fixed ones came to a month on average in
     * the past year: fuel, maintenance, parking and so on.
     */
    private function averageVariableCosts(Vehicle $vehicle): float
    {
        return (float) rescue(
            fn () => collect($vehicle->calculateAverageMonthlyCostsByType(today()->subYear()->toDateString(), today()->toDateString()))
                ->except(['Insurance', 'Road tax'])
                ->sum(),
            0,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function item(Model $record, string $heading, string $icon, ?string $routeName, DateTimeInterface|string|null $date, array $extra = []): array
    {
        $date = $date ? Carbon::parse($date) : null;
        $country = config('countries')[$extra['country'] ?? null] ?? null;

        return [
            'key' => $record->getKey().'-'.($date?->format('Ymd') ?? ''),
            'heading' => $heading,
            'icon' => $icon,
            'group' => self::GROUPS[$record::class] ?? 'road',
            'logo' => $extra['logo'] ?? null,
            'flag' => $country ? ['isoCode' => $country['iso_code'], 'name' => $country['name']] : null,
            'date' => $date?->isoFormat('ll'),
            'sort' => $date->timestamp ?? 0,
            'month' => $date ? (string) str($date->isoFormat('MMMM Y'))->ucfirst() : '',
            'price' => is_numeric($extra['price'] ?? null) && (float) $extra['price'] > 0
                ? '€ '.number_format((float) $extra['price'], 2, ',', '.')
                : null,
            'mileage' => ! empty($extra['mileage']) ? $extra['mileage'].' km' : null,
            'location' => filled($extra['location'] ?? null) ? (string) $extra['location'] : null,
            'badges' => array_values(array_filter($extra['badges'] ?? [])),
            'roads' => $extra['roads'] ?? [],
            'url' => $routeName ? route($routeName.'.edit', $record->getKey()) : null,
        ];
    }

    /**
     * @return array{title: string, color: string, icon: string|null}|null
     */
    private function badge(?string $title, string $color = 'gray', ?string $icon = null): ?array
    {
        return filled($title) ? ['title' => $title, 'color' => $color, 'icon' => $icon] : null;
    }

    /**
     * @return array{title: string, color: string, icon: string|null}|null
     */
    private function enumBadge((HasLabel&HasIcon)|null $case): ?array
    {
        return $this->badge($case?->getLabel(), icon: $case?->getIcon());
    }

    private function invoiceDate(string $month, mixed $invoiceDay): Carbon
    {
        $date = Carbon::parse($month.'-01');

        // Clamp to the length of the month: an invoice day of 31 does not exist in February.
        return $date->day(min(max((int) $invoiceDay, 1), $date->daysInMonth));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array{month: string, items: list<array<string, mixed>>}>
     */
    private function groupByMonth(Collection $items): array
    {
        return $items
            ->sortByDesc('sort')
            ->groupBy('month')
            ->map(fn (Collection $items, string $month) => ['month' => $month, 'items' => $items->values()->all()])
            ->values()
            ->all();
    }
}
