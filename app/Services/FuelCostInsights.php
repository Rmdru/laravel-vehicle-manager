<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RefuelingClimateControl;
use App\Enums\RefuelingDrivingStyle;
use App\Enums\RefuelingRoutes;
use App\Enums\RefuelingTires;
use App\Models\FuelPrice;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Support\Odometer;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the refuelings tell about the fuel costs, calculated by the app: why
 * they changed, what a cheaper gas station would have saved, and what the
 * circumstances of driving cost.
 */
class FuelCostInsights
{
    /** Months the fuel costs of now are the average of. */
    private const CHANGE_MONTHS = 3;

    /** Refuelings with a consumption a period needs at least to tell anything. */
    private const REFUELINGS_AT_LEAST = 2;

    /** Visits a gas station needs at least to be the one to compare with: one says little. */
    private const VISITS_AT_LEAST = 2;

    /** Refuelings a circumstance needs at least to be compared. */
    private const CIRCUMSTANCE_AT_LEAST = 2;

    /** The circumstances recorded with a refueling, by attribute. */
    private const CIRCUMSTANCES = [
        'driving_style' => ['label' => 'Driving style', 'enum' => RefuelingDrivingStyle::class],
        'routes' => ['label' => 'Routes', 'enum' => RefuelingRoutes::class],
        'climate_control' => ['label' => 'Climate control', 'enum' => RefuelingClimateControl::class],
        'tires' => ['label' => 'Tires', 'enum' => RefuelingTires::class],
    ];

    public function __construct(private readonly FuelAdviceFacts $fuelAdviceFacts)
    {
        //
    }

    /**
     * The fuel costs per month of the last months against the same months a
     * year before (or else the months before), split up into what the price,
     * the distance driven and the consumption did. The parts add up to the
     * difference (by logarithmic mean weights), so nothing is left unexplained.
     * Null when either period has too few refuelings.
     *
     * @return array<string, mixed>|null
     */
    public function change(Vehicle $vehicle): ?array
    {
        $refuelings = $this->refuelings($vehicle)->filter(fn (Refueling $refueling) => $this->distance($refueling) > 0 && (float) $refueling->fuel_consumption > 0);
        $latest = $refuelings->max(fn (Refueling $refueling) => $refueling->date->toDateString());

        if ($latest === null) {
            return null;
        }

        // Up to the latest refueling: after it the distance is only estimated.
        $until = CarbonImmutable::parse($latest);
        $from = $until->subMonthsNoOverflow(self::CHANGE_MONTHS)->addDay();
        $odometer = Odometer::of($vehicle);
        $now = $this->figures($refuelings, $odometer, $from, $until);
        $sameSeason = true;
        $before = $this->figures($refuelings, $odometer, $from->subYear(), $until->subYear());

        if ($before === null) {
            $sameSeason = false;
            $before = $this->figures($refuelings, $odometer, $from->subMonthsNoOverflow(self::CHANGE_MONTHS), $from->subDay());
        }

        if ($now === null || $before === null) {
            return null;
        }

        $difference = $now['costs'] - $before['costs'];
        // The logarithmic mean of the costs: what makes the parts add up.
        $weight = abs($difference) < 0.005 ? $now['costs'] : $difference / log($now['costs'] / $before['costs']);
        $part = fn (string $figure) => $weight * log($now[$figure] / $before[$figure]);
        $unit = $this->unit($vehicle);

        return [
            'period' => $this->period($now),
            'compared' => $this->period($before),
            'sameSeason' => $sameSeason,
            'now' => $this->money($now['costs']),
            'before' => $this->money($before['costs']),
            'difference' => $this->signed($difference),
            'trend' => $difference <=> 0,
            'perYear' => $this->signed($difference * 12),
            'parts' => [
                $this->part('price', __('Fuel price'), 'ic:round-local-gas-station', $part('price'),
                    '€ '.$this->number($before['price'], 3).' → € '.$this->number($now['price'], 3).'/'.$unit['short']),
                $this->part('distance', __('Distance driven'), 'mdi:map-marker-distance', $part('km'),
                    __(':before → :now km per month', ['before' => $this->number($before['km'], 0), 'now' => $this->number($now['km'], 0)])),
                $this->part('consumption', __('Consumption'), 'mdi:engine', $part('consumption'),
                    $this->number($before['consumption'], 1).' → '.$this->number($now['consumption'], 1).' '.$unit['consumption']),
            ],
        ];
    }

    /**
     * The figures of a period: the kilometers a month by the odometer, and the
     * consumption and the price paid of its refuelings, weighted by distance.
     * Null with too few refuelings.
     *
     * @param  Collection<int, Refueling>  $refuelings
     * @return array{from: CarbonImmutable, until: CarbonImmutable, km: float, consumption: float, price: float, costs: float}|null
     */
    private function figures(Collection $refuelings, Odometer $odometer, CarbonImmutable $from, CarbonImmutable $until): ?array
    {
        $period = $refuelings->filter(fn (Refueling $refueling) => $refueling->date->between($from, $until) && (float) $refueling->unit_price > 0);

        if ($period->count() < self::REFUELINGS_AT_LEAST) {
            return null;
        }

        $distance = $period->sum(fn (Refueling $refueling) => $this->distance($refueling));
        $liters = $period->sum(fn (Refueling $refueling) => $this->used($refueling));
        $months = ($from->diffInDays($until) + 1) / 30.44;
        [$start] = $odometer->at($from->subDay());
        [$end] = $odometer->at($until);
        $km = ($start !== null && $end !== null && $end > $start ? $end - $start : $distance) / $months;
        $consumption = $liters / $distance * 100;
        $price = $period->sum(fn (Refueling $refueling) => $this->used($refueling) * (float) $refueling->unit_price) / $liters;

        return [
            'from' => $from,
            'until' => $until,
            'km' => $km,
            'consumption' => $consumption,
            'price' => $price,
            'costs' => $km * $consumption / 100 * $price,
        ];
    }

    /**
     * @param  array{from: CarbonImmutable, until: CarbonImmutable}  $figures
     */
    private function period(array $figures): string
    {
        return $figures['from']->isoFormat('D MMM YYYY').' – '.$figures['until']->isoFormat('D MMM YYYY');
    }

    /**
     * @return array{key: string, label: string, icon: string, detail: string, amount: string, value: float, trend: int}
     */
    private function part(string $key, string $label, string $icon, float $amount, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'detail' => $detail,
            'amount' => $this->signed($amount),
            'value' => round($amount, 2),
            'trend' => round($amount, 2) <=> 0.0,
        ];
    }

    /**
     * What would have been saved in the past year by refueling at the
     * cheapest of the usual gas stations in the home country every time. A
     * gas station is compared by what was paid there against the average
     * price of the country on the day, as prices move. Per fuel type, the one
     * refueled most first. Null without prices to compare with.
     *
     * @return array<string, mixed>|null
     */
    public function savings(Vehicle $vehicle): ?array
    {
        $since = today()->subYear();
        $home = $vehicle->fuelPriceHomeCountry(FuelPrice::query()->distinct()->pluck('country')->all());
        $average = $this->fuelAdviceFacts->countryAverage(null, $since);
        $unit = $this->unit($vehicle)['short'];

        $fuelTypes = $this->refuelings($vehicle)
            ->filter(fn (Refueling $refueling) => $refueling->date->gte($since) && $refueling->country === $home
                && filled($refueling->gas_station) && filled($refueling->fuel_type) && (float) $refueling->amount > 0)
            ->map(fn (Refueling $refueling) => [
                'refueling' => $refueling,
                'difference' => ($country = $average($refueling)) === null ? null : (float) $refueling->unit_price - $country,
            ])
            ->filter(fn (array $item) => $item['difference'] !== null)
            ->groupBy(fn (array $item) => $item['refueling']->fuel_type)
            ->map(function (Collection $items, string $fuelType) use ($unit) {
                $stations = $items
                    ->groupBy(fn (array $item) => mb_strtolower(trim((string) $item['refueling']->gas_station)))
                    ->map(function (Collection $visits) {
                        $liters = $visits->sum(fn (array $item) => (float) $item['refueling']->amount);

                        return [
                            'name' => trim((string) $visits->first()['refueling']->gas_station),
                            'visits' => $visits->count(),
                            'liters' => $liters,
                            // Negative: cheaper than the average of the country.
                            'difference' => $visits->sum(fn (array $item) => $item['difference'] * (float) $item['refueling']->amount) / $liters,
                        ];
                    });
                $best = $stations->where('visits', '>=', self::VISITS_AT_LEAST)->sortBy('difference')->first();

                if ($best === null || $stations->count() < 2) {
                    return null;
                }

                $rows = $stations
                    ->map(fn (array $station) => [...$station, 'extra' => max(0.0, ($station['difference'] - $best['difference']) * $station['liters'])])
                    ->sortBy('difference');

                return [
                    'key' => $fuelType,
                    'label' => trans('fuel_types')[$fuelType] ?? $fuelType,
                    'liters' => $items->sum(fn (array $item) => (float) $item['refueling']->amount),
                    'best' => $best['name'],
                    'total' => round($rows->sum('extra'), 2),
                    'savings' => $this->money($rows->sum('extra')),
                    'stations' => $rows->map(fn (array $station) => [
                        'name' => $station['name'],
                        'visits' => $station['visits'],
                        'best' => $station['name'] === $best['name'],
                        'difference' => $this->difference($station['difference'], $unit),
                        'trend' => round($station['difference'], 3) <=> 0.0,
                        'extra' => $station['extra'] >= 0.005 ? $this->money($station['extra']) : null,
                    ])->values()->all(),
                ];
            })
            ->filter()
            ->sortByDesc('liters')
            ->values();

        if ($fuelTypes->isEmpty()) {
            return null;
        }

        return [
            'country' => config('countries')[$home]['name'] ?? $home,
            'total' => $this->money($fuelTypes->sum('total')),
            'fuelTypes' => $fuelTypes->map(fn (array $fuelType) => collect($fuelType)->except(['liters', 'total'])->all())->all(),
        ];
    }

    /**
     * The consumption under each circumstance recorded with the refuelings of
     * the period, against the average, and what the difference costs per
     * 1000 km at the latest price. A refueling with more than one route or
     * kind of climate control counts for each. Only circumstances of which
     * two values have enough refuelings to compare.
     *
     * @return array<string, mixed>|null
     */
    public function circumstances(Vehicle $vehicle, ?string $start = null, ?string $end = null): ?array
    {
        $refuelings = $this->refuelings($vehicle)
            ->filter(fn (Refueling $refueling) => $this->distance($refueling) > 0 && (float) $refueling->fuel_consumption > 0)
            ->when($start, fn (Collection $refuelings) => $refuelings->filter(fn (Refueling $refueling) => $refueling->date->gte(CarbonImmutable::parse($start))))
            ->when($end, fn (Collection $refuelings) => $refuelings->filter(fn (Refueling $refueling) => $refueling->date->lte(CarbonImmutable::parse($end)->endOfDay())));

        if ($refuelings->count() < self::CIRCUMSTANCE_AT_LEAST * 2) {
            return null;
        }

        $average = $this->consumption($refuelings);
        $price = (float) $refuelings->sortByDesc(fn (Refueling $refueling) => $refueling->date->toDateString().' '.$refueling->created_at)->first()->unit_price;
        $unit = $this->unit($vehicle);

        $circumstances = collect(self::CIRCUMSTANCES)
            ->map(function (array $config, string $attribute) use ($refuelings, $average, $price, $unit) {
                $values = $refuelings
                    ->flatMap(fn (Refueling $refueling) => collect((array) $refueling->{$attribute})
                        ->map(fn ($value) => $value instanceof BackedEnum ? $value->value : $value)
                        ->filter()
                        ->unique()
                        ->map(fn ($value) => ['value' => (string) $value, 'refueling' => $refueling]))
                    ->groupBy('value')
                    ->filter(fn (Collection $items) => $items->count() >= self::CIRCUMSTANCE_AT_LEAST);

                if ($values->count() < 2) {
                    return null;
                }

                return [
                    'key' => $attribute,
                    'label' => __($config['label']),
                    'values' => $values
                        ->map(function (Collection $items, string $value) use ($config, $average, $price, $unit) {
                            $consumption = $this->consumption($items->pluck('refueling'));
                            $case = $config['enum']::tryFrom($value);
                            $costs = ($consumption - $average) / 100 * 1000 * $price;

                            return [
                                'key' => $value,
                                'label' => $case?->getLabel() ?? $value,
                                'icon' => $case?->getIcon(),
                                'refuelings' => $items->count(),
                                'consumption' => $this->number($consumption, 1).' '.$unit['consumption'],
                                'value' => $consumption,
                                'difference' => ($consumption >= $average ? '+' : '−').$this->number(abs($consumption / $average - 1) * 100, 0).'%',
                                'costs' => $this->signed($costs),
                                'trend' => round($costs, 2) <=> 0.0,
                            ];
                        })
                        ->sortBy('value')
                        ->map(fn (array $row) => collect($row)->except('value')->all())
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->values();

        if ($circumstances->isEmpty()) {
            return null;
        }

        return [
            'average' => $this->number($average, 1).' '.$unit['consumption'],
            'price' => '€ '.$this->number($price, 3).'/'.$unit['short'],
            'circumstances' => $circumstances->all(),
        ];
    }

    /**
     * The refuelings with what the powertrain runs on: no AdBlue, and no
     * charging for a vehicle that runs on fuel, as their units differ.
     *
     * @return Collection<int, Refueling>
     */
    private function refuelings(Vehicle $vehicle): Collection
    {
        $electric = $vehicle->powertrain === 'electricity';

        return $vehicle->refuelings()->get()
            ->filter(fn (Refueling $refueling) => $electric === str_starts_with($refueling->fuel_type, 'Electricity') && $refueling->fuel_type !== 'Adblue')
            ->values();
    }

    private function distance(Refueling $refueling): int
    {
        return max(0, (int) $refueling->mileage_end - (int) $refueling->mileage_begin);
    }

    /**
     * What was used on the distance of a refueling, by its consumption.
     */
    private function used(Refueling $refueling): float
    {
        return (float) $refueling->fuel_consumption * $this->distance($refueling) / 100;
    }

    /**
     * The consumption of refuelings together, weighted by distance.
     *
     * @param  Collection<int, Refueling>  $refuelings
     */
    private function consumption(Collection $refuelings): float
    {
        return $refuelings->sum(fn (Refueling $refueling) => $this->used($refueling)) / $refuelings->sum(fn (Refueling $refueling) => $this->distance($refueling)) * 100;
    }

    /**
     * @return array{short: string, consumption: string}
     */
    private function unit(Vehicle $vehicle): array
    {
        $powertrain = trans('powertrains')[$vehicle->powertrain] ?? [];

        return ['short' => $powertrain['unit_short'] ?? 'l', 'consumption' => $powertrain['consumption_unit'] ?? 'l/100km'];
    }

    /**
     * How much below or above the average of the country a gas station is.
     */
    private function difference(float $difference, string $unit): string
    {
        return __(round($difference, 3) <= 0 ? ':amount below average' : ':amount above average', [
            'amount' => '€ '.$this->number(abs($difference), 3).'/'.$unit,
        ]);
    }

    private function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    private function money(float $amount): string
    {
        return '€ '.$this->number($amount, 2);
    }

    private function signed(float $amount): string
    {
        return (round($amount, 2) < 0 ? '−' : '+').$this->money(abs($amount));
    }
}
