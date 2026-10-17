<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FuelPrice;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Support\Cost;
use App\Traits\VehicleStats;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data for the dashboard widgets of the current vehicle.
 */
class DashboardService
{
    use VehicleStats;

    /** Rows in the list of most recent costs, at most. */
    private const LATEST_COSTS = 100;

    /**
     * The period the statistics of the VehicleStats trait are calculated over.
     *
     * @var array{startDate?: string|null, endDate?: string|null}
     */
    private array $filters = [];

    public function __construct(
        private readonly VehicleStatusService $statusService,
        private readonly RdwService $rdw,
        private readonly FuelAdviceFacts $fuelAdviceFacts,
        private readonly FuelAdviceService $fuelAdvice,
        private readonly VehicleAdviceService $vehicleAdvice,
    ) {
        //
    }

    /**
     * The notifications of the vehicle, ordered by urgency.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        $notifications = collect($this->statusService->getNotifications($vehicle));
        $fuelAdvice = $this->fuelAdvice->notification($vehicle);

        // The fuel advice goes on top, and the tips that are urgent follow the
        // other warnings; then "all is well" no longer is.
        $advice = array_values(array_filter([$fuelAdvice, ...$this->vehicleAdvice->notifications($vehicle)]));

        if ($advice !== []) {
            $notifications = $notifications->reject(fn (array $notification) => $notification['type'] === 'success')->push(...$advice);
        }

        // Most urgent first: critical, then warnings, then information.
        return $notifications
            ->sortBy('priority')
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string, primary: string, secondary: string, icon: string}>
     */
    public function statuses(Vehicle $vehicle): array
    {
        $statuses = [];
        $maintenance = $vehicle->maintenance_status;

        if (! empty($maintenance)) {
            $byTime = str($maintenance['timeDiffHumans'])->ucfirst()->toString();
            $byDistance = __('About :distance km (:timeTillDistanceDeadlineHumans)', [
                'distance' => $maintenance['distance'],
                'timeTillDistanceDeadlineHumans' => $maintenance['timeTillDistanceDeadlineHumans'],
            ]);

            // Whichever deadline comes first is the one to show prominently.
            $distanceFirst = $maintenance['daysTillDistanceDeadline'] < $maintenance['time'];

            $statuses[] = [
                'title' => __('Maintenance'),
                'primary' => $distanceFirst ? $byDistance : $byTime,
                'secondary' => $distanceFirst ? $byTime : $byDistance,
                'icon' => 'mdi:car-wrench',
            ];
        }

        $apk = $vehicle->apk_status;

        if (! empty($apk)) {
            $statuses[] = [
                'title' => __('MOT'),
                'primary' => str($apk['timeDiffHumans'])->ucfirst()->toString(),
                'secondary' => '',
                'icon' => 'ic:baseline-security',
            ];
        }

        return $statuses;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stats(Vehicle $vehicle, ?string $start = null, ?string $end = null): array
    {
        $this->filters = ['startDate' => $start, 'endDate' => $end];

        $unit = trans('powertrains')[$vehicle->powertrain]['consumption_unit'] ?? 'l/100km';

        $ratioTitle = match (true) {
            str($vehicle->powertrain)->contains('diesel', true) => __('Ratio premium diesel vs regular diesel'),
            str($vehicle->powertrain)->contains('electric', true) => __('Ratio AC charging vs DC charging'),
            default => __('Ratio premium gasoline vs Unleaded 95'),
        };

        return array_values(array_filter([
            $this->stat(__('Average monthly costs'), 'mdi:hand-coin-outline', round($this->calculateAverageMonthlyCosts(), 2), round($this->calculateAverageMonthlyCosts(true), 2), prefix: '€'),
            $this->stat(__('Average costs per kilometer'), 'uil:euro-circle', $this->calculateCostsPerKilometer(), $this->calculateCostsPerKilometer(true), prefix: '€', suffix: '/km'),
            $this->stat(__('Average fuel costs per kilometer'), 'ic:baseline-local-gas-station', $this->calculateFuelCostsPerKilometer(), $this->calculateFuelCostsPerKilometer(true), prefix: '€', suffix: '/km', hideWhenEmpty: true),
            $this->stat(__('Average fuel usage'), 'mdi:engine', $this->calculateAverageFuelConsumption(), $this->calculateAverageFuelConsumption(true), suffix: $unit),
            $this->factoryConsumptionStat($vehicle, $unit),
            $this->stat(__('Average on-board computer deviation'), 'mdi:plus-minus-variant', $this->calculateAvgOnboardComputerDeviation(), $this->calculateAvgOnboardComputerDeviation(true), suffix: $unit, hideWhenEmpty: true),
            $this->stat(__('Average range'), 'ic:round-route', $this->calculateAverageRange(), $this->calculateAverageRange(true), suffix: 'km', higherIsBetter: true),
            $this->stat(__('Average monthly distance'), 'mdi:map-marker-distance', $this->calculateAverageMonthlyDistance(), $this->calculateAverageMonthlyDistance(true), suffix: 'km', higherIsBetter: true),
            $this->stat(__('Average speed'), 'mdi:speedometer', $this->calculateAvgSpeed(), $this->calculateAvgSpeed(true), suffix: 'km/h', higherIsBetter: true, hideWhenEmpty: true),
            $this->stat($ratioTitle, 'ic:round-local-gas-station', $this->calculateRatioPremiumFuel(), $this->calculateRatioPremiumFuel(true), suffix: '%', higherIsBetter: true, hideWhenEmpty: true),
        ]));
    }

    /**
     * How much more (or less) the vehicle uses than the manufacturer specified,
     * by WLTP or NEDC as the RDW registered it.
     *
     * @return array<string, mixed>|null
     */
    private function factoryConsumptionStat(Vehicle $vehicle, string $unit): ?array
    {
        $factory = $this->rdw->getFactoryConsumption($vehicle->rdw_data['fuel'] ?? [], (string) $vehicle->powertrain);
        $average = $this->calculateAverageFuelConsumption();

        if ($factory === null || $average <= 0) {
            return null;
        }

        $deviation = fn (float $consumption) => $consumption > 0 ? round(($consumption / $factory['value'] - 1) * 100, 1) : 0.0;

        return $this->stat(
            __('Fuel usage compared to specification (:consumption)', [
                'consumption' => number_format($factory['value'], 1, ',', '.').' '.$unit,
            ]),
            'mdi:fuel',
            $deviation($average),
            $deviation($this->calculateAverageFuelConsumption(true)),
            suffix: '%',
        );
    }

    /**
     * @return array{fuelTypes: array<string, string>, countries: list<array<string, mixed>>}
     */
    public function fuelPricesAbroad(Vehicle $vehicle): array
    {
        // Per fuel type: the prices of a country can come from sources that are not equally recent.
        $latestDates = FuelPrice::query()
            ->select('country', 'fuel_type', DB::raw('MAX(date) as latest_date'))
            ->groupBy('country', 'fuel_type');

        $fuelPrices = FuelPrice::query()
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('fuel_prices.country', '=', 'latest.country')
                    ->on('fuel_prices.fuel_type', '=', 'latest.fuel_type')
                    ->on('fuel_prices.date', '=', 'latest.latest_date');
            })
            ->leftJoin('fuel_detour_aggregates', function ($join) use ($vehicle) {
                $join->on('fuel_detour_aggregates.fuel_price_id', '=', 'fuel_prices.id')
                    ->where('fuel_detour_aggregates.vehicle_id', $vehicle->id);
            })
            ->select([
                'fuel_prices.country',
                'fuel_prices.date',
                'fuel_prices.fuel_type',
                'fuel_prices.price',
                'fuel_detour_aggregates.max_detour_only_fuel_costs',
                'fuel_detour_aggregates.max_detour_all_costs',
            ])
            // The price before the latest one, to show whether the price went up or down.
            ->addSelect(['previous_price' => FuelPrice::query()
                ->from('fuel_prices', 'previous')
                ->select('previous.price')
                ->whereColumn('previous.country', 'fuel_prices.country')
                ->whereColumn('previous.fuel_type', 'fuel_prices.fuel_type')
                ->whereColumn('previous.date', '<', 'fuel_prices.date')
                ->orderByDesc('previous.date')
                ->limit(1),
            ])
            ->get();

        $fuelTypes = $fuelPrices->pluck('fuel_type')->unique()
            ->mapWithKeys(fn (string $type) => [$type => trans('fuel_types.'.$type)])
            ->sort()
            ->all();

        $countries = config('countries');
        $home = $vehicle->fuelPriceHomeCountry($fuelPrices->pluck('country')->unique()->values()->all());

        return [
            'fuelTypes' => $fuelTypes,
            'countries' => $fuelPrices
                ->groupBy('country')
                ->sortKeys()
                ->map(fn ($prices, string $country) => [
                    'key' => $country,
                    'name' => $countries[$country]['name'] ?? $country,
                    'isoCode' => $countries[$country]['iso_code'] ?? null,
                    'isHome' => $country === $home,
                    'prices' => $prices->mapWithKeys(fn (FuelPrice $price) => [$price->fuel_type => [
                        'date' => $price->date->format('d-m-Y'),
                        'price' => number_format((float) $price->price, 3, ',', '.'),
                        'previousPrice' => $price->getAttribute('previous_price') === null ? null : number_format((float) $price->getAttribute('previous_price'), 3, ',', '.'),
                        'trend' => $price->getAttribute('previous_price') === null ? 0 : round((float) $price->price, 3) <=> round((float) $price->getAttribute('previous_price'), 3),
                        // Only for a fuel the vehicle can run on, and once the detour has been
                        // calculated (null from the left join): 0 means it is not worth the detour.
                        'detourFuel' => $vehicle->suitableForFuel($price->fuel_type) ? $price->getAttribute('max_detour_only_fuel_costs') : null,
                        'detourAll' => $vehicle->suitableForFuel($price->fuel_type) ? $price->getAttribute('max_detour_all_costs') : null,
                    ]])->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The fuel prices over time, per country that has prices in the period.
     * Without a period: the past year. The chart opens on the country the vehicle
     * is registered in, and marks the day the next refueling is expected, as
     * long as the period runs up to today.
     *
     * @return array{home: string|null, expectedRefuelDate: string|null, countries: list<array{key: string, name: string, isoCode: string|null, series: list<array{key: int, label: string, points: list<array{date: string, price: float}>}>}>}
     */
    public function fuelPricesChart(Vehicle $vehicle, ?string $start = null, ?string $end = null): array
    {
        $ranged = filled($start) && filled($end);
        $from = $ranged ? Carbon::parse($start)->startOfDay() : now()->subYear()->startOfDay();
        $until = $ranged ? Carbon::parse($end)->endOfDay() : now()->endOfDay();

        // The key is what the color is tied to, so a fuel type keeps its color
        // whatever the period or the country.
        $known = FuelPrice::query()->distinct()->pluck('fuel_type');
        $keys = collect(array_keys(trans('fuel_types')))->intersect($known)->values()->flip();
        $labels = trans('fuel_types');
        $countries = config('countries');

        return [
            'home' => $vehicle->country_registration,
            'expectedRefuelDate' => $until->isBefore(today())
                ? null
                : $this->fuelAdviceFacts->expectedRefuelDate($vehicle)?->toDateString(),
            'countries' => FuelPrice::query()
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $until)
                ->orderBy('date')
                ->get(['country', 'fuel_type', 'date', 'price'])
                ->groupBy('country')
                ->sortKeys()
                ->map(fn ($prices, string $country) => [
                    'key' => $country,
                    'name' => $countries[$country]['name'] ?? $country,
                    'isoCode' => $countries[$country]['iso_code'] ?? null,
                    'series' => $prices
                        ->groupBy('fuel_type')
                        ->map(fn ($prices, string $type) => [
                            'key' => $keys[$type] ?? $keys->count(),
                            'label' => $labels[$type] ?? $type,
                            'points' => $prices
                                ->map(fn (FuelPrice $price) => ['date' => $price->date->format('Y-m-d'), 'price' => (float) $price->price])
                                ->values()
                                ->all(),
                        ])
                        ->sortBy('key')
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fuelUsageByType(Vehicle $vehicle, ?string $start = null, ?string $end = null): array
    {
        $unit = trans('powertrains')[$vehicle->powertrain]['consumption_unit'] ?? 'l/100km';
        $number = fn ($value) => $value !== null ? number_format((float) $value, 2, ',', '.') : '-';

        // The consumption of a tank is only known at the next refueling, hence LEAD().
        return DB::query()
            ->fromSub(function ($query) use ($vehicle) {
                $query->selectRaw('fuel_type, amount, date, LEAD(fuel_consumption) OVER (ORDER BY date, id) as next_fuel_consumption, LEAD(avg_speed) OVER (ORDER BY date, id) as next_speed')
                    ->from('refuelings')
                    ->where('vehicle_id', $vehicle->id);
            }, 'refuelings_with_next')
            ->selectRaw('fuel_type, AVG(next_fuel_consumption) as avg_consumption, AVG(next_speed) as avg_speed, SUM(amount) as total_amount, COUNT(*) as refueling_count')
            ->whereNotNull('fuel_type')
            ->whereNotNull('next_fuel_consumption')
            ->when($start, fn ($query) => $query->whereDate('date', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('date', '<=', $end))
            ->groupBy('fuel_type')
            ->orderBy('fuel_type')
            ->get()
            ->map(fn ($row) => [
                'fuelType' => trans('fuel_types')[$row->fuel_type] ?? $row->fuel_type,
                'consumption' => $number($row->avg_consumption).' '.$unit,
                'speed' => $number($row->avg_speed).' km/h',
                'amount' => $number($row->total_amount).' l',
                'count' => (int) $row->refueling_count,
            ])
            ->all();
    }

    /**
     * The gas stations with the lowest and the highest average price per fuel
     * type: of all fuel types together, a station per fuel type, and of each
     * fuel type, the one refueled most often first.
     *
     * @return array{all: array{cheapest: list<array<string, mixed>>, mostExpensive: list<array<string, mixed>>}, fuelTypes: list<array{key: string, label: string, cheapest: list<array<string, mixed>>, mostExpensive: list<array<string, mixed>>}>}
     */
    public function topGasStations(Vehicle $vehicle, ?string $start, ?string $end): array
    {
        $unit = trans('powertrains')[$vehicle->powertrain]['unit_short'] ?? 'l';
        $price = fn ($value) => '€ '.number_format((float) $value, 3, ',', '.').'/'.$unit;
        // Refuelings without a fuel type form a group of their own.
        $label = fn (string $fuelType) => $fuelType === '' ? __('Other') : (trans('fuel_types')[$fuelType] ?? $fuelType);

        $stations = Refueling::query()
            ->where('vehicle_id', $vehicle->id)
            ->when($start, fn ($query) => $query->whereDate('date', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('date', '<=', $end))
            ->select('fuel_type', 'gas_station')
            ->selectRaw('COUNT(*) as visit_count, AVG(unit_price) as avg_price, MIN(unit_price) as lowest_price, MAX(unit_price) as highest_price')
            ->groupBy('fuel_type', 'gas_station')
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'key' => (string) $row->fuel_type,
                'gasStation' => $row->gas_station,
                'fuelType' => $label((string) $row->fuel_type),
                'visits' => (int) $row->visit_count,
                'average' => (float) $row->avg_price,
                'averagePrice' => $price($row->avg_price),
                'lowestPrice' => $price($row->lowest_price),
                'highestPrice' => $price($row->highest_price),
            ]);

        $fuelTypes = $stations
            ->groupBy('key')
            ->sortByDesc(fn (Collection $stations) => $stations->sum('visits'))
            ->map(fn (Collection $stations, string $fuelType) => [
                'key' => $fuelType,
                'label' => $label($fuelType),
                ...$this->rankGasStations($stations),
            ])
            ->values()
            ->all();

        return ['all' => $this->rankGasStations($stations), 'fuelTypes' => $fuelTypes];
    }

    /**
     * The five cheapest and the five most expensive of the gas stations.
     *
     * @param  Collection<int, covariant array<string, mixed>>  $stations
     * @return array{cheapest: list<array<string, mixed>>, mostExpensive: list<array<string, mixed>>}
     */
    private function rankGasStations(Collection $stations): array
    {
        // On a tie in price, the most visited station first.
        $top = fn (string $direction) => $stations
            ->sortBy([['average', $direction], ['visits', 'desc']])
            ->take(5)
            ->map(fn (array $station) => Arr::except($station, ['key', 'average']))
            ->values()
            ->all();

        return ['cheapest' => $top('asc'), 'mostExpensive' => $top('desc')];
    }

    /**
     * The cost types costs were made for, the most expensive first.
     *
     * @param  list<string>  $only  Cost types to limit the result to; all when empty.
     * @return list<array{key: int, label: string, amount: string, share: float, icon: string}>
     */
    public function averageMonthlyCostsByType(Vehicle $vehicle, ?string $start, ?string $end, array $only = []): array
    {
        $types = Cost::types($vehicle);
        $keys = array_flip(array_keys($types));

        $averages = collect($vehicle->calculateAverageMonthlyCostsByType((string) $start, (string) $end))
            ->filter(fn (float $amount, string $type) => $amount > 0 && $this->includesCostType($only, $type))
            ->sortDesc();

        $highest = (float) $averages->max();

        return $averages
            ->map(fn (float $amount, string $type) => [
                // Ties the type to its color in the costs chart.
                'key' => $keys[$type] ?? 0,
                'label' => __($type),
                'amount' => '€ '.number_format($amount, 2, ',', '.'),
                'share' => round($amount / $highest * 100, 1),
                'icon' => $types[$type]['icon'] ?? 'mdi:help-circle-outline',
            ])
            ->values()
            ->all();
    }

    /**
     * Stacked monthly costs per cost type, by default for the past year.
     *
     * @return array{labels: list<string>, series: list<array{key: int, label: string, data: list<float>}>}
     */
    public function costsChart(Vehicle $vehicle, ?string $start, ?string $end, array $only = []): array
    {
        $costs = empty($start) || empty($end)
            ? $vehicle->calculateMonthlyCosts(now()->subYear()->toDateString(), now()->toDateString())
            : $vehicle->calculateMonthlyCosts($start, $end);

        // Every cost type with costs is a series of its own. The key is what the
        // color is tied to, so a type keeps its color whatever else has costs.
        $series = collect(array_keys(Cost::types($vehicle)))
            ->filter(fn (string $type) => $this->includesCostType($only, $type))
            ->map(fn (string $type, int $key) => [
                'key' => $key,
                'label' => __($type),
                'data' => collect($costs['monthlyCosts'])
                    ->map(fn (array $month) => round((float) ($month[$type] ?? 0), 2))
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $series) => array_sum($series['data']) > 0)
            ->values()
            ->all();

        return [
            'labels' => array_map(strval(...), $costs['labels']),
            'series' => $series,
        ];
    }

    /**
     * The costs made in the period, newest first. Without a period: the
     * current month.
     *
     * @param  list<string>  $only  Cost types to limit the result to; all when empty.
     * @return list<array<string, mixed>>
     */
    public function latestCosts(Vehicle $vehicle, ?string $start = null, ?string $end = null, array $only = []): array
    {
        $countries = config('countries');
        $ranged = filled($start) && filled($end);
        $from = $ranged ? Carbon::parse($start)->startOfDay() : now()->startOfMonth();
        $until = $ranged ? Carbon::parse($end)->endOfDay() : now()->endOfMonth();
        $rows = collect();

        foreach (Cost::types($vehicle) as $type => $config) {
            if (! $this->includesCostType($only, $type)) {
                continue;
            }

            $monthly = $config['monthly'] ?? false;
            $dateColumn = $config['dateColumn'];

            $records = $config['model']::query()
                ->where('vehicle_id', $vehicle->id)
                ->when($monthly, fn ($query) => $query
                    ->whereDate('start_date', '<=', $until)
                    ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $from)))
                // Only the newest of a type can make it into the list.
                ->when(! $monthly, fn ($query) => $query
                    ->whereDate($dateColumn, '>=', $from)
                    ->whereDate($dateColumn, '<=', $until)
                    ->orderByDesc($dateColumn)
                    ->limit(self::LATEST_COSTS))
                ->get();

            foreach ($records as $record) {
                $dates = $monthly
                    ? $this->invoiceDates($record, $from, $until)
                    : [Carbon::parse($record->{$dateColumn})];

                $item = $this->costItem($type, $record, $vehicle);
                $url = route($config['link'].'.edit', $record->getKey());

                foreach ($dates as $date) {
                    $rows->push([
                        'key' => $record->getKey().'-'.$date->format('Ymd'),
                        'type' => __($type),
                        'item' => $countries[$item]['name'] ?? ($item === '' ? '' : __(ucfirst($item))),
                        'price' => '€ '.number_format((float) $record->{$config['priceField']}, 2, ',', '.'),
                        'date' => $date->isoFormat('ll'),
                        'sort' => $date->timestamp,
                        'icon' => $config['icon'],
                        'url' => $url,
                    ]);
                }
            }
        }

        return $rows->sortByDesc('sort')->take(self::LATEST_COSTS)->values()->all();
    }

    /**
     * The days a monthly invoiced record (insurance, tax) was invoiced within
     * the period. Invoices of today or later have not been made yet.
     *
     * @return list<Carbon>
     */
    private function invoiceDates(Model $record, Carbon $from, Carbon $until): array
    {
        $first = Carbon::parse($record->getAttribute('start_date'))->max($from);
        $last = collect([$until, today()->subDay()->endOfDay(), $record->getAttribute('end_date') ? Carbon::parse($record->getAttribute('end_date'))->endOfDay() : null])
            ->filter()
            ->min();

        $dates = [];

        for ($month = $first->copy()->startOfMonth(); $month->lte($last); $month->addMonth()) {
            $date = $month->copy()->addDays(min((int) $record->getAttribute('invoice_day'), $month->daysInMonth) - 1);

            if ($date->betweenIncluded($first->copy()->startOfDay(), $last)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * @param  list<string>  $only
     */
    private function includesCostType(array $only, string $type): bool
    {
        return $only === [] || in_array($type, $only, true);
    }

    private function costItem(string $type, Model $record, Vehicle $vehicle): string
    {
        $section = fn () => filled($record->getAttribute('end_location'))
            ? $record->getAttribute('start_location').' - '.$record->getAttribute('end_location')
            : (string) $record->getAttribute('start_location');

        return (string) str(match ($type) {
            'Fuel' => $record->getAttribute('amount').' '.(trans('powertrains')[$vehicle->powertrain]['unit_short'] ?? 'l'),
            'Maintenance' => (string) $record->getAttribute('description'),
            'Reconditioning', 'Service', 'Accident' => str_replace('_', ' ', (string) $record->getAttribute('type')),
            'Insurance' => (string) $record->getAttribute('type'),
            'Parking' => (string) $record->getAttribute('location'),
            'Toll', 'Transport trains & ferry services' => $section(),
            'Fine' => (string) $record->getAttribute('fact'),
            'Vignette', 'Environmental sticker' => (string) $record->getAttribute('country'),
            'Product' => (string) $record->getAttribute('name'),
            default => '',
        })->words(10);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stat(
        string $title,
        string $icon,
        float $value,
        float $latest,
        string $prefix = '',
        string $suffix = '',
        bool $higherIsBetter = false,
        bool $hideWhenEmpty = false,
    ): ?array {
        if ($hideWhenEmpty && empty($value)) {
            return null;
        }

        $format = fn (float $number) => trim($prefix.' '.rtrim(rtrim(number_format($number, 3, ',', '.'), '0'), ',').' '.$suffix);

        return [
            'title' => $title,
            'icon' => $icon,
            'value' => $format($value),
            'latest' => $format($latest),
            'trend' => $latest <=> $value,
            'color' => match (true) {
                $latest === $value => 'gray',
                ($latest > $value) === $higherIsBetter => 'success',
                default => 'danger',
            },
        ];
    }
}
