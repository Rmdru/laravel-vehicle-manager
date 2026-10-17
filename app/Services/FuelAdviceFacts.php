<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FuelDetourAggregate;
use App\Models\FuelPrice;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Traits\VehicleStats;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The facts a fuel advice is based on, calculated rather than left to the AI
 * model: when the next refueling is due, and what the fuel costs where.
 */
class FuelAdviceFacts
{
    use VehicleStats;

    /** Refuelings the driving pattern is derived from, at most. */
    private const REFUELINGS = 10;

    /** Days of prices the model gets to see per country. */
    private const RECENT_DAYS = 14;

    /** Weeks of prices the pattern over the days of the week is derived from. */
    private const PATTERN_WEEKS = 12;

    /** How the prices of each source come about. */
    private const PRICE_KINDS = [
        'netherlands' => 'average pump price (CBS), published a few days late',
        'belgium' => 'official maximum price set by the government',
        'luxembourg' => 'official maximum price set by the government',
        'germany' => 'median pump price of the day (Tankerkönig)',
    ];

    /** What a fuel is compared with in a country that has no prices of it, in order. */
    private const COMPARABLE = [
        'Unleaded 95 (E10)' => ['Unleaded 95 (E5)'],
        'Unleaded 95 (E5)' => ['Unleaded 95 (E10)'],
        'Super Plus (E5)' => ['Super Plus (E10)', 'Unleaded 95 (E5)'],
        'Super Plus (E10)' => ['Super Plus (E5)', 'Unleaded 95 (E10)'],
        'V-Power 100' => ['Super Plus (E5)', 'Super Plus (E10)'],
        'Ultimate 102' => ['Super Plus (E5)', 'Super Plus (E10)'],
        'E85' => ['Unleaded 95 (E10)'],
        'Premium diesel' => ['Diesel'],
    ];

    /** The fuel of the "1 in 4 times fill up with Super Plus (E5)" notification. */
    private const SUPER_PLUS = 'Super Plus (E5)';

    /**
     * Null when there is nothing to advise on: no fuel, or no prices of it.
     *
     * @return array<string, mixed>|null
     */
    public function for(Vehicle $vehicle): ?array
    {
        if (in_array($vehicle->powertrain, ['electricity', 'hydrogen'], true)) {
            return null;
        }

        $usual = $this->fuelType($vehicle);

        if ($usual === null) {
            return null;
        }

        // The advice is about the next refueling, which may have to be Super Plus.
        $superPlus = $this->superPlusDue($vehicle);
        $fuelType = $superPlus ? self::SUPER_PLUS : $usual;
        $candidates = [$fuelType, ...self::COMPARABLE[$fuelType] ?? []];

        // Per country the fuel itself, or else the one most like it.
        $prices = FuelPrice::query()
            ->whereIn('fuel_type', $candidates)
            ->whereDate('date', '>=', today()->subWeeks(self::PATTERN_WEEKS))
            ->orderBy('date')
            ->get(['id', 'country', 'fuel_type', 'date', 'price'])
            ->groupBy('country')
            ->map(function (Collection $prices) use ($candidates) {
                $type = collect($candidates)->first(fn (string $type) => $prices->contains('fuel_type', $type));

                return $prices->where('fuel_type', $type)->values();
            });

        if ($prices->isEmpty()) {
            return null;
        }

        $home = $vehicle->fuelPriceHomeCountry(array_values(array_map('strval', $prices->keys()->all())));
        $homePrice = $prices->has($home) ? (float) $prices[$home]->last()->price : null;
        $user = $vehicle->user()->first();
        // The city of the user, as their profile located it. Without one the
        // driver is taken to live in the country the vehicle is registered in.
        $location = $user !== null && $user->latitude !== null && $user->longitude !== null && filled($user->city)
            ? ['lat' => (float) $user->latitude, 'lon' => (float) $user->longitude]
            : null;

        return [
            'today' => today()->toDateString(),
            'weekday' => today()->englishDayOfWeek,
            'fuel_type' => $fuelType,
            'usual_fuel_type' => $usual,
            'super_plus_due' => $superPlus,
            'home_country' => $home,
            'driver_location' => $location
                ? ['city' => $user->city, 'known' => true]
                : ['city' => 'unknown', 'country' => $home, 'known' => false],
            'vehicle' => array_filter([
                'tank_capacity_liters' => $vehicle->tank_capacity ?: null,
            ]),
            'next_refueling' => $this->nextRefueling($vehicle),
            'countries' => $prices
                ->map(fn (Collection $prices, string $country) => $this->country($vehicle, $country, $prices, $country === $home ? null : $homePrice, $location))
                ->sortBy('latest_price')
                ->values()
                ->all(),
            'weekday_pattern_home_country' => $prices->has($home) ? $this->weekdayPattern($prices[$home]) : null,
            // What a gas station costs compared to the average hardly depends on the fuel.
            'own_gas_stations' => $this->gasStations($vehicle, array_values(array_unique([...$candidates, $usual, ...self::COMPARABLE[$usual] ?? []]))),
        ];
    }

    /**
     * Whether a refueling with the one fuel can be compared with a price of the other.
     */
    public static function comparable(?string $fuelType, ?string $with): bool
    {
        return $fuelType !== null && $with !== null
            && ($fuelType === $with || in_array($with, self::COMPARABLE[$fuelType] ?? [], true));
    }

    /**
     * The gas stations the vehicle was refueled at most in the past year, with
     * how much less (or more) was paid there than the average price of the
     * country on the day: the price itself says little, as prices move.
     *
     * @param  list<string>  $fuelTypes  The fuel of the advice, and the ones comparable to it.
     * @return array<int, array<string, mixed>>
     */
    private function gasStations(Vehicle $vehicle, array $fuelTypes): array
    {
        $since = today()->subYear();

        $averages = FuelPrice::query()
            ->whereIn('fuel_type', $fuelTypes)
            ->whereDate('date', '>=', $since->copy()->subWeek())
            ->orderBy('date')
            ->get(['country', 'fuel_type', 'date', 'price'])
            ->groupBy(fn (FuelPrice $price) => $price->country.'|'.$price->fuel_type);

        // The average of the country on the day, or the last one of the week before.
        $average = function (Refueling $refueling) use ($averages, $fuelTypes): ?float {
            $date = Carbon::parse($refueling->date);

            foreach ([$refueling->fuel_type, ...$fuelTypes] as $fuelType) {
                $price = $averages->get($refueling->country.'|'.$fuelType)
                    ?->last(fn (FuelPrice $price) => $price->date->lte($date) && $price->date->gte($date->copy()->subWeek()));

                if ($price !== null) {
                    return (float) $price->price;
                }
            }

            return null;
        };

        return Refueling::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('fuel_type', $fuelTypes)
            ->whereDate('date', '>=', $since)
            ->whereNotNull('gas_station')
            ->get(['date', 'country', 'fuel_type', 'gas_station', 'unit_price'])
            ->map(fn (Refueling $refueling) => [
                'refueling' => $refueling,
                'difference' => ($country = $average($refueling)) === null ? null : (float) $refueling->unit_price - $country,
            ])
            ->filter(fn (array $item) => $item['difference'] !== null)
            ->groupBy(fn (array $item) => $item['refueling']->gas_station)
            // A single visit says little about a gas station.
            ->filter(fn (Collection $visits) => $visits->count() >= 2)
            ->map(fn (Collection $visits, string $name) => [
                'name' => $name,
                'country' => $visits->first()['refueling']->country,
                'visits' => $visits->count(),
                'average_price_paid' => round((float) $visits->avg(fn (array $item) => $item['refueling']->unit_price), 3),
                // Negative: cheaper than the average of the country.
                'average_difference_with_country_average' => round((float) $visits->avg('difference'), 3),
                'last_visit' => Carbon::parse($visits->max(fn (array $item) => $item['refueling']->date))->toDateString(),
            ])
            ->sortBy('average_difference_with_country_average')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * The fuel the vehicle is refueled with most, of the ones there are prices
     * of (or of a comparable fuel).
     */
    private function fuelType(Vehicle $vehicle): ?string
    {
        $prices = FuelPrice::query()->distinct()->pluck('fuel_type')->all();
        $known = collect(trans('fuel_types'))->keys()
            ->filter(fn (string $type) => array_intersect([$type, ...self::COMPARABLE[$type] ?? []], $prices) !== [])
            ->all();

        $refueled = Refueling::query()
            ->where('vehicle_id', $vehicle->id)
            ->newest()
            ->limit(self::REFUELINGS)
            ->pluck('fuel_type')
            ->filter(fn (?string $type) => in_array($type, $known, true))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return $refueled ?? collect($vehicle->fuel_types ?? [])->first(fn (string $type) => in_array($type, $known, true));
    }

    /**
     * When the next refueling is due, going by the distance driven per day and
     * what a tank holds. Without a consumption or a tank capacity: by the time
     * between the refuelings.
     *
     * @return array<string, mixed>|null
     */
    public function nextRefueling(Vehicle $vehicle): ?array
    {
        $refuelings = Refueling::query()
            ->where('vehicle_id', $vehicle->id)
            ->newest()
            ->limit(self::REFUELINGS + 1)
            ->get(['date', 'mileage_end', 'percentage', 'fuel_consumption']);

        if ($refuelings->count() < 2) {
            return null;
        }

        $last = $refuelings->first();
        $first = $refuelings->last();
        $days = Carbon::parse($first->date)->diffInDays(Carbon::parse($last->date));
        $distance = (int) $last->mileage_end - (int) $first->mileage_end;

        if ($days < 1 || $distance <= 0) {
            return null;
        }

        $perDay = $distance / $days;
        $interval = $days / ($refuelings->count() - 1);
        $consumption = (float) $refuelings->where('fuel_consumption', '>', 0)->avg('fuel_consumption');
        $tank = (int) $vehicle->tank_capacity;
        $percentage = (float) ($last->percentage ?: 100);

        $range = null;
        $daysPerTank = $interval;

        if ($tank > 0 && $consumption > 0) {
            $liters = $tank * ($percentage / 100 - config('fuel_advice.reserve'));
            $range = max($liters, 0) / $consumption * 100;
            $daysPerTank = $range / $perDay;
        }

        $since = Carbon::parse($last->date)->diffInDays(today());
        $expected = Carbon::parse($last->date)->addDays((int) round($daysPerTank));

        return array_filter([
            'last_refueling' => Carbon::parse($last->date)->toDateString(),
            'days_since_last_refueling' => (int) $since,
            'tank_percentage_after_last_refueling' => $percentage,
            'estimated_tank_percentage_now' => $tank > 0 && $consumption > 0
                ? (int) round(max(0, $percentage - $since * $perDay * $consumption / 100 / $tank * 100))
                : null,
            'average_km_per_day' => round($perDay, 1),
            'average_days_between_refuelings' => round($interval, 1),
            'average_consumption_l_per_100km' => $consumption > 0 ? round($consumption, 2) : null,
            'estimated_range_km_until_reserve' => $range === null ? null : (int) round($range),
            'expected_date' => $expected->max(today())->toDateString(),
            'overdue' => $expected->isBefore(today()),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  Collection<int, FuelPrice>  $prices  Oldest first.
     * @param  array{lat: float, lon: float}|null  $location  Where the driver lives.
     * @return array<string, mixed>
     */
    private function country(Vehicle $vehicle, string $country, Collection $prices, ?float $homePrice, ?array $location): array
    {
        $latest = $prices->last();
        $price = (float) $latest->price;
        $date = Carbon::parse($latest->date);

        // The change since the price of (about) the given number of days before.
        $change = function (int $days) use ($prices, $date, $price): ?float {
            $before = $prices->last(fn (FuelPrice $item) => Carbon::parse($item->date)->lte($date->copy()->subDays($days)));

            return $before === null ? null : round($price - (float) $before->price, 3);
        };

        $facts = [
            'country' => $country,
            'fuel_type' => $latest->fuel_type,
            'price_kind' => self::PRICE_KINDS[$country] ?? 'average pump price',
            'latest_price' => round($price, 3),
            'latest_price_date' => $date->toDateString(),
            'change_7_days' => $change(7),
            'change_30_days' => $change(30),
            'lowest_price_12_weeks' => round((float) $prices->min('price'), 3),
            'highest_price_12_weeks' => round((float) $prices->max('price'), 3),
            'recent_prices' => $prices
                ->filter(fn (FuelPrice $item) => Carbon::parse($item->date)->gt($date->copy()->subDays(self::RECENT_DAYS)))
                ->mapWithKeys(fn (FuelPrice $item) => [Carbon::parse($item->date)->toDateString() => round((float) $item->price, 3)])
                ->all(),
        ];

        if ($homePrice !== null) {
            $detour = FuelDetourAggregate::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('fuel_price_id', $latest->id)
                ->first();

            $savings = $vehicle->tank_capacity ? ($homePrice - $price) * $vehicle->tank_capacity : null;

            $facts['difference_with_home_country'] = round($price - $homePrice, 3);
            $facts['savings_per_full_tank_eur'] = $savings === null ? null : round($savings, 2);
            // How far the vehicle can drive out of its way before the cheaper fuel no longer pays off.
            $facts['break_even_detour_km_fuel_costs_only'] = $detour?->max_detour_only_fuel_costs;
            $facts['break_even_detour_km_all_costs'] = $detour?->max_detour_all_costs;
            $facts = [...$facts, ...$this->detour($vehicle, $country, $savings, $location)];
        }

        return array_filter($facts, fn ($value) => $value !== null);
    }

    /**
     * Whether refueling in the country pays off from where the driver lives:
     * the savings on a full tank minus the fuel to get there and back.
     *
     * @param  array{lat: float, lon: float}|null  $location
     * @return array<string, mixed>
     */
    private function detour(Vehicle $vehicle, string $country, ?float $savings, ?array $location): array
    {
        $points = (array) config('fuel_advice.border_points.'.$country, []);

        if ($location === null || $points === []) {
            return ['distance_from_driver_km' => 'unknown', 'worth_the_detour' => false];
        }

        $nearest = collect($points)->min(fn (array $point) => $this->kilometers($location, $point));
        $distance = (int) round($nearest * config('fuel_advice.road_factor'));
        $costsPerKilometer = $this->calculateAverageFuelCostsPerKilometer($vehicle->id);
        $net = $savings !== null && $costsPerKilometer > 0 ? $savings - 2 * $distance * $costsPerKilometer : null;

        return [
            // One way, by road, estimated.
            'distance_from_driver_km' => $distance,
            'detour_fuel_costs_eur' => $costsPerKilometer > 0 ? round(2 * $distance * $costsPerKilometer, 2) : null,
            'net_savings_per_full_tank_eur' => $net === null ? null : round($net, 2),
            'worth_the_detour' => $net !== null && $net > 0,
        ];
    }

    /**
     * The distance as the crow flies, by the haversine formula.
     *
     * @param  array{lat: float, lon: float}  $from
     * @param  array<int, float>  $to  Latitude, longitude.
     */
    private function kilometers(array $from, array $to): float
    {
        $latitude = deg2rad($to[0] - $from['lat']);
        $longitude = deg2rad($to[1] - $from['lon']);

        $a = sin($latitude / 2) ** 2 + cos(deg2rad($from['lat'])) * cos(deg2rad($to[0])) * sin($longitude / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Whether the next refueling has to be Super Plus, as the notification that
     * says so tells, as long as it is on.
     */
    private function superPlusDue(Vehicle $vehicle): bool
    {
        $enabled = collect($vehicle->notifications ?? [])
            ->first(fn ($settings) => is_array($settings) && array_key_exists('periodic_super_plus', $settings));

        if ($enabled !== null && ! $enabled['periodic_super_plus']) {
            return false;
        }

        return $vehicle->superPlusDue();
    }

    /**
     * How much the price is above or below the average of its week, in euro
     * cents, per day of the week. Null as long as there are too few weeks.
     *
     * @param  Collection<int, FuelPrice>  $prices
     * @return array<string, float>|null
     */
    private function weekdayPattern(Collection $prices): ?array
    {
        $weeks = $prices
            ->groupBy(fn (FuelPrice $price) => Carbon::parse($price->date)->format('o-W'))
            // A week of a single price says nothing about the days.
            ->filter(fn (Collection $week) => $week->count() >= 5);

        if ($weeks->count() < 6) {
            return null;
        }

        return $weeks
            ->flatMap(function (Collection $week) {
                $average = (float) $week->avg('price');

                return $week->map(fn (FuelPrice $price) => [
                    'day' => Carbon::parse($price->date)->dayOfWeekIso,
                    'deviation' => ((float) $price->price - $average) * 100,
                ]);
            })
            ->groupBy('day')
            ->sortKeys()
            ->mapWithKeys(fn (Collection $days, int $day) => [
                Carbon::create(2024, 1, $day)->englishDayOfWeek => round((float) $days->avg('deviation'), 2),
            ])
            ->all();
    }

    /**
     * The day the next refueling is expected, for the fuel prices chart.
     */
    public function expectedRefuelDate(Vehicle $vehicle): ?CarbonInterface
    {
        $next = $this->nextRefueling($vehicle);

        return isset($next['expected_date']) ? Carbon::parse($next['expected_date']) : null;
    }
}
