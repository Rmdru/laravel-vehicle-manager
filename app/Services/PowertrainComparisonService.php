<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\PowertrainAlternativesAdvisor;
use App\Ai\ApiKeys;
use App\Models\PowertrainComparison;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\VehiclePresenter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Compares a vehicle with a realistic alternative model per powertrain, on
 * the use of its owner: what each would cost a month, by kind of cost, with
 * its range and its consumption in practice.
 *
 * An AI model chooses the alternatives and estimates what the app cannot
 * know, once, and again when the vehicle changes or a year went by. The app
 * calculates the costs from those estimates, the prices of the day and the
 * records of the vehicle itself: every day anew.
 */
class PowertrainComparisonService
{
    /** The estimates of the model of every vehicle, with the bounds they are checked against. */
    private const ESTIMATES = [
        'build_year', 'power_hp', 'fuel_per_100km', 'electricity_kwh_per_100km', 'wltp_electric_range_km',
        'tank_capacity', 'gasoline_tank_l', 'price_now_eur', 'depreciation_per_year_eur',
        'maintenance_per_year_eur', 'insurance_per_year_eur', 'road_tax_per_year_eur',
    ];

    /** The kinds of costs, in the order they are shown; the depreciation only in the total. */
    public const COSTS = ['fuel', 'road_tax', 'insurance', 'maintenance', 'depreciation'];

    /** Raised when what calculate() gives changes, so that the costs of earlier today are not shown. */
    private const COSTS_VERSION = 2;

    public function __construct(private readonly PowertrainComparisonFacts $facts)
    {
        //
    }

    /**
     * Has the model choose the alternatives and estimate their costs, and
     * stores them. Asked again once when an answer has values that make no
     * sense; the better of the two answers is kept.
     *
     * @throws Throwable When the AI model gives no (usable) answer.
     */
    public function generate(Vehicle $vehicle, string $locale): PowertrainComparison
    {
        $usage = $this->facts->usage($vehicle);
        $powertrains = collect(trans('powertrains', [], 'en'))
            ->except($vehicle->powertrain)
            ->map(fn (array $powertrain) => $powertrain['name'])
            ->all();
        $language = VehicleAdviceService::LANGUAGES[$locale] ?? 'English';
        $model = config('powertrain_comparison.model');
        // By reference: after a failure, the fallback model is asked.
        $advisor = function (?string $key) use ($language, $powertrains, &$model) {
            return new PowertrainAlternativesAdvisor($language, $powertrains, $key, $model);
        };
        $question = $advisor(null)->question($this->question($vehicle, $usage));

        $best = null;

        $ask = function () use ($advisor, $question, &$model) {
            try {
                return ApiKeys::ask(config('powertrain_comparison.provider'), $advisor, $question);
            } catch (Throwable $exception) {
                if ($model === config('powertrain_comparison.fallback_model')) {
                    throw $exception;
                }

                $model = config('powertrain_comparison.fallback_model');

                return ApiKeys::ask(config('powertrain_comparison.provider'), $advisor, $question);
            }
        };

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $ask();
            } catch (Throwable $exception) {
                // Asked again for a better answer: the first one will do.
                if ($best !== null) {
                    break;
                }

                throw $exception;
            }

            $answer = [
                'current' => $this->estimates((array) ($response['current'] ?? []), (string) $vehicle->powertrain),
                'alternatives' => $this->alternatives((array) ($response['alternatives'] ?? []), array_keys($powertrains)),
                'model' => $response->meta->model ?? $model,
            ];

            if ($answer['current'] !== null && ($best === null || count($answer['alternatives']['valid']) > count($best['alternatives']['valid']))) {
                $best = $answer;
            }

            if ($answer['current'] !== null && $answer['alternatives']['complete']) {
                break;
            }
        }

        if ($best === null) {
            throw new RuntimeException('The powertrain alternatives advisor gave no usable estimates of the vehicle.');
        }

        // The prices without a feed are needed for the costs; one estimate per country lasts a quarter.
        rescue(fn () => $this->facts->estimatePrices($vehicle->country_registration ?: 'netherlands'));

        return PowertrainComparison::query()->updateOrCreate(
            ['vehicle_id' => $vehicle->id, 'locale' => $locale],
            [
                'current' => $best['current'],
                'alternatives' => $best['alternatives']['valid'],
                'input_hash' => $this->fingerprint($vehicle),
                'model' => $best['model'],
            ],
        );
    }

    /**
     * What the model gets to know about the vehicle and how it is used.
     *
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function question(Vehicle $vehicle, array $usage): array
    {
        $rdw = (array) $vehicle->rdw_data;
        $filled = fn (array $values) => array_filter($values, fn ($value) => $value !== null && $value !== '' && $value !== []);

        return [
            'today' => today()->toDateString(),
            'vehicle' => $filled([
                'brand' => config('vehicles.brands')[$vehicle->brand] ?? (string) $vehicle->brand,
                'model' => $vehicle->model,
                'version' => $vehicle->version,
                'engine' => $vehicle->engine,
                'powertrain' => trans('powertrains', [], 'en')[$vehicle->powertrain]['name'] ?? $vehicle->powertrain,
                'build_year' => $vehicle->construction_date?->year,
                'mileage_km' => $vehicle->mileage_latest ?: null,
                'tank_capacity_l' => $vehicle->tank_capacity ?: null,
                'curb_weight_kg' => $rdw['massa_rijklaar'] ?? null,
                'catalog_price_eur' => $rdw['catalogusprijs'] ?? null,
                'body' => $rdw['inrichting'] ?? null,
            ]),
            'owner' => $filled([
                'country' => $vehicle->country_registration,
                'city' => $vehicle->user?->city,
            ]),
            // Not the consumption of the owner: the model gives the usual one of the make and model, which the
            // driving style is measured against. Nor the maintenance: the records are often not complete.
            'usage' => $filled([
                'km_per_year' => $usage['km_per_year'],
                'insurance_per_year_eur' => $usage['insurance_per_year'],
                'insurance_cover' => $usage['insurance_cover'],
                'road_tax_per_year_eur' => $usage['road_tax_per_year'],
            ]),
        ];
    }

    /**
     * The estimates of a vehicle when every one of them makes sense for its
     * powertrain, rounded; null otherwise.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, int|float>|null
     */
    private function estimates(array $values, string $powertrain): ?array
    {
        $bounds = config('powertrain_comparison.bounds');
        $spec = config("powertrain_comparison.powertrains.{$powertrain}", []);
        $estimates = [];

        foreach (self::ESTIMATES as $field) {
            $value = round((float) ($values[$field] ?? 0), 2);
            [$min, $max] = $bounds[$field];
            $max ??= (int) today()->year + 1;

            // Zero is fine for what the powertrain does not have, and for the cost that need not be there.
            if ($value == 0 && in_array($field, ['fuel_per_100km', 'electricity_kwh_per_100km', 'wltp_electric_range_km', 'tank_capacity', 'gasoline_tank_l', 'depreciation_per_year_eur', 'road_tax_per_year_eur'], true)) {
                $estimates[$field] = 0;

                continue;
            }

            if ($value < $min || $value > $max) {
                return null;
            }

            $estimates[$field] = in_array($field, ['fuel_per_100km', 'electricity_kwh_per_100km', 'tank_capacity', 'gasoline_tank_l'], true) ? $value : (int) round($value);
        }

        $charges = $spec['charges'] ?? false;
        $burns = ($spec['fuel'] ?? null) !== null;

        $complete = (! $burns || ($estimates['fuel_per_100km'] > 0 && $estimates['tank_capacity'] > 0))
            && (! $charges || ($estimates['electricity_kwh_per_100km'] > 0 && $estimates['wltp_electric_range_km'] > 0));

        return $complete ? $estimates : null;
    }

    /**
     * The alternatives that make sense, one per powertrain, in the order of
     * the powertrains; complete tells whether none was left out for it.
     *
     * @param  array<int, mixed>  $alternatives
     * @param  list<string>  $powertrains
     * @return array{valid: list<array<string, mixed>>, complete: bool}
     */
    private function alternatives(array $alternatives, array $powertrains): array
    {
        $complete = true;

        $valid = collect($alternatives)
            ->filter(fn ($alternative) => is_array($alternative) && in_array($alternative['powertrain'] ?? null, $powertrains, true))
            ->unique('powertrain')
            ->map(function (array $alternative) use (&$complete) {
                $estimates = $this->estimates($alternative, $alternative['powertrain']);
                $name = fn (string $field, int $length) => Str::limit(trim(strip_tags((string) ($alternative[$field] ?? ''))), $length, '');

                if ($estimates === null || $name('brand', 40) === '' || $name('model', 60) === '') {
                    $complete = false;

                    return null;
                }

                return [
                    'powertrain' => $alternative['powertrain'],
                    'brand' => $name('brand', 40),
                    'model' => $name('model', 60),
                    'version' => $name('version', 80),
                    ...$estimates,
                    'reason' => Str::limit(trim(strip_tags((string) ($alternative['reason'] ?? ''))), 200),
                ];
            })
            ->filter()
            ->sortBy(fn (array $alternative) => array_search($alternative['powertrain'], $powertrains, true))
            ->values()
            ->all();

        return ['valid' => $valid, 'complete' => $complete];
    }

    /**
     * What tells whether the alternatives have to be chosen again: the vehicle
     * itself, not how it is driven, which the costs follow every day.
     */
    public function fingerprint(Vehicle $vehicle): string
    {
        return hash('sha256', (string) json_encode([
            $vehicle->brand, $vehicle->model, $vehicle->version, $vehicle->engine, $vehicle->powertrain,
            $vehicle->construction_date?->toDateString(), (float) $vehicle->tank_capacity, $vehicle->country_registration,
        ]));
    }

    public function latest(Vehicle $vehicle, string $locale): ?PowertrainComparison
    {
        return $vehicle->powertrainComparisons()->where('locale', $locale)->first();
    }

    /**
     * Whether the alternatives have to be chosen (again): there are none, the
     * vehicle changed, or they are a year old.
     */
    public function outdated(Vehicle $vehicle, string $locale): bool
    {
        $comparison = $this->latest($vehicle, $locale);

        return $comparison === null
            || $comparison->input_hash !== $this->fingerprint($vehicle)
            || $comparison->updated_at->lt(now()->subDays(config('powertrain_comparison.refresh_after')));
    }

    /**
     * Has the alternatives chosen after the response, unless that was tried
     * for this vehicle only minutes ago; forced, also then. Only for an owner
     * who logged in recently, as with the other advices. True while they are
     * being chosen.
     */
    public function chooseLater(Vehicle $vehicle, string $locale, bool $force = false): bool
    {
        $key = $this->cacheKey($vehicle, $locale);

        if (! User::query()->whereKey($vehicle->user_id)->recentlyLoggedIn()->exists()) {
            return false;
        }

        if ($force) {
            Cache::forget($key.':tried');
        }

        if (Cache::add($key.':tried', true, now()->addMinutes(config('powertrain_comparison.regenerate_after')))) {
            Cache::put($key.':choosing', true, now()->addSeconds(config('powertrain_comparison.timeout') * 3));

            defer(function () use ($vehicle, $locale, $key) {
                try {
                    $this->generate($vehicle, $locale);
                    Cache::forget($key.':failed');
                } catch (Throwable $exception) {
                    report($exception);
                    Cache::put($key.':failed', true, now()->addMinutes(config('powertrain_comparison.regenerate_after')));
                } finally {
                    Cache::forget($key.':choosing');
                }
            });
        }

        return Cache::has($key.':choosing');
    }

    /**
     * Whether the alternatives can be chosen again by hand: not again within minutes.
     */
    public function canChoose(Vehicle $vehicle, string $locale): bool
    {
        return ! Cache::has($this->cacheKey($vehicle, $locale).':tried');
    }

    /**
     * Calculates the costs again with the prices and the records of now,
     * instead of those of earlier today.
     */
    public function forgetCosts(Vehicle $vehicle, string $locale): void
    {
        $comparison = $this->latest($vehicle, $locale);

        if ($comparison !== null) {
            Cache::forget($this->costsKey($comparison));
        }
    }

    private function cacheKey(Vehicle $vehicle, string $locale): string
    {
        return "powertrain-comparison:{$vehicle->id}:{$locale}";
    }

    private function costsKey(PowertrainComparison $comparison): string
    {
        return "powertrain-comparison:{$comparison->id}:{$comparison->updated_at->timestamp}:costs:v".self::COSTS_VERSION.':'.today()->toDateString();
    }

    /**
     * The comparison in the current locale, for the dashboard. The
     * alternatives are chosen after the response when they have to be.
     *
     * @return array<string, mixed>
     */
    public function forDashboard(Vehicle $vehicle): array
    {
        $locale = app()->getLocale();
        $comparison = $this->latest($vehicle, $locale);
        $pending = $this->outdated($vehicle, $locale) && $this->chooseLater($vehicle, $locale);

        return [
            'comparison' => $comparison === null ? null : [
                ...Cache::remember($this->costsKey($comparison), today()->endOfDay(), fn () => $this->calculate($vehicle, $comparison)),
                'model' => $comparison->model,
                'chosenAt' => $comparison->updated_at->isoFormat('D MMMM YYYY'),
            ],
            'pending' => $pending,
            'failed' => $comparison === null && ! $pending && Cache::has($this->cacheKey($vehicle, $locale).':failed'),
            'canChoose' => $this->canChoose($vehicle, $locale),
            'chooseUrl' => route('powertrain-comparison.store'),
            'refreshUrl' => route('powertrain-comparison.refresh'),
        ];
    }

    /**
     * The vehicle and its alternatives with their range, consumption and
     * costs, on the use of the vehicle and the prices of today.
     *
     * @return array<string, mixed>
     */
    public function calculate(Vehicle $vehicle, PowertrainComparison $comparison): array
    {
        $usage = $this->facts->usage($vehicle);
        $prices = $this->facts->prices($vehicle, array_keys($usage['fuel_amounts']));
        $current = $comparison->current;
        $powertrain = (string) $vehicle->powertrain;
        $charges = config("powertrain_comparison.powertrains.{$powertrain}.charges", false);
        $burns = config("powertrain_comparison.powertrains.{$powertrain}.fuel") !== null;

        // How the owner drives against the usual driver of the model: the alternatives get the same.
        $usual = $burns ? $current['fuel_per_100km'] : $current['electricity_kwh_per_100km'];
        $style = $usage['consumption'] && $usual > 0
            ? max(config('powertrain_comparison.bounds.driving_style')[0], min(config('powertrain_comparison.bounds.driving_style')[1], $usage['consumption'] / $usual))
            : 1.0;

        // The vehicle itself: its own consumption and the tank it has, where known.
        $own = [
            ...$current,
            'fuel_per_100km' => $burns && $usage['consumption'] ? $usage['consumption'] : $current['fuel_per_100km'],
            'electricity_kwh_per_100km' => ! $burns && $usage['consumption'] ? $usage['consumption'] : $current['electricity_kwh_per_100km'],
            'tank_capacity' => $burns && (float) $vehicle->tank_capacity > 0 ? (float) $vehicle->tank_capacity : $current['tank_capacity'],
            'build_year' => $vehicle->construction_date?->year ?? $current['build_year'],
        ];

        $brand = config('vehicles.brands')[$vehicle->brand] ?? (string) $vehicle->brand;
        $car = $this->car($own, $powertrain, $usage['km_per_year'], $prices, 1.0, $this->ownFuelPerKm($usage, $prices, $charges && ! $burns));
        $charged = ($usage['fuel_amounts']['Electricity AC'] ?? 0) + ($usage['fuel_amounts']['Electricity DC'] ?? 0);

        // The prices of what the vehicle itself refueled, the most first.
        if ($usage['fuel_amounts'] !== []) {
            $car['prices'] = $this->pricesOf(array_keys(collect($usage['fuel_amounts'])->sortDesc()->all()), $prices);
        }

        // An electric vehicle that recorded how it charged: its own part of fast charging.
        if ($charges && ! $burns && $charged > 0) {
            $car['consumption']['fastShare'] = round(($usage['fuel_amounts']['Electricity DC'] ?? 0) / $charged, 2);
        }

        $cars = [
            [
                ...$car,
                'key' => 'current',
                'current' => true,
                'brand' => $brand,
                'brandLogo' => VehiclePresenter::brandLogo($brand),
                'model' => $vehicle->model,
                'version' => $vehicle->version ?: $vehicle->engine,
                'purchasePrice' => (float) $vehicle->purchase_price > 0 ? (float) $vehicle->purchase_price : null,
                'reason' => null,
                'consumptionEstimated' => $usage['consumption'] === null,
                'costs' => $this->ownCosts($car['costs'], $usage),
            ],
            ...array_map(fn (array $alternative) => [
                ...$this->car($alternative, $alternative['powertrain'], $usage['km_per_year'], $prices, $style),
                'key' => $alternative['powertrain'],
                'current' => false,
                'brand' => $alternative['brand'],
                'brandLogo' => VehiclePresenter::brandLogo($alternative['brand']),
                'model' => $alternative['model'],
                'version' => $alternative['version'],
                'purchasePrice' => null,
                'reason' => $alternative['reason'],
                'consumptionEstimated' => true,
            ], $comparison->alternatives),
        ];

        $dates = collect($prices)->pluck('date')->filter();

        return [
            'cars' => $cars,
            'kmPerYear' => $usage['km_per_year'],
            'kmEstimated' => $usage['km_estimated'],
            'drivingStyle' => round($style, 2),
            'pricesDate' => $dates->isEmpty() ? null : Carbon::parse($dates->max())->isoFormat('D MMMM'),
            'calculatedAt' => now()->isoFormat('D MMMM HH:mm'),
        ];
    }

    /**
     * What the fuel of the vehicle itself costs by the kilometer: what it
     * refueled in the past year at the prices of today, over the distance it
     * covered. Null when the refuelings say too little. An electric vehicle
     * only when the charging was recorded as slow or fast.
     *
     * @param  array<string, mixed>  $usage
     * @param  array<string, array{price: float, source: string, date: string|null}>  $prices
     */
    private function ownFuelPerKm(array $usage, array $prices, bool $electric): ?float
    {
        $amounts = $usage['fuel_amounts'];

        if ($usage['fuel_distance'] < 1000 || $amounts === []
            || ($electric && array_diff(array_keys($amounts), ['Electricity AC', 'Electricity DC']) !== [])) {
            return null;
        }

        $costs = collect($amounts)->sum(fn (float $amount, string $type) => $amount * ($prices[$type]['price'] ?? 0));

        return $costs > 0 ? $costs / $usage['fuel_distance'] : null;
    }

    /**
     * The costs of the vehicle itself: what its records say where they do,
     * else the estimates.
     *
     * @param  array<string, array{year: float, estimated: bool}>  $costs
     * @param  array<string, mixed>  $usage
     * @return array<string, array{year: float, estimated: bool}>
     */
    private function ownCosts(array $costs, array $usage): array
    {
        // On its own consumption the fuel is no estimate, even when the refuelings say too little of the costs.
        if ($usage['consumption'] !== null) {
            $costs['fuel']['estimated'] = false;
        }

        foreach (['road_tax' => 'road_tax_per_year', 'insurance' => 'insurance_per_year', 'maintenance' => 'maintenance_per_year'] as $cost => $known) {
            if ($usage[$known] !== null) {
                $costs[$cost] = ['year' => (float) $usage[$known], 'estimated' => false];
            }
        }

        return $costs;
    }

    /**
     * The range, the consumption and the costs a year of a vehicle, from its
     * estimates, on the kilometers a year and the prices of today.
     *
     * @param  array<string, mixed>  $car  The estimates.
     * @param  array<string, array{price: float, source: string, date: string|null}>  $prices
     * @param  float  $style  The driving style of the owner, applied to the consumption.
     * @param  float|null  $fuelPerKm  What the fuel costs by the kilometer when that is known already.
     * @return array<string, mixed>
     */
    private function car(array $car, string $powertrain, int $kmPerYear, array $prices, float $style, ?float $fuelPerKm = null): array
    {
        $config = config('powertrain_comparison');
        $spec = $config['powertrains'][$powertrain] ?? ['fuel' => 'Unleaded 95 (E10)'];
        $fuel = $spec['fuel'] ?? null;
        $charges = $spec['charges'] ?? false;
        $price = fn (string $type) => $prices[$type]['price'] ?? $config['fallback_prices'][$type] ?? 0;

        $fuelConsumption = $fuel !== null ? round($car['fuel_per_100km'] * $style, 2) : null;
        $electricity = $charges ? round($car['electricity_kwh_per_100km'] * $style, 1) : null;
        $electricRange = $charges ? (int) round($car['wltp_electric_range_km'] * $config['ev_range_factor']) : null;
        $gasolineShare = $config['gas_vehicles']['gasoline_share'][$powertrain] ?? 0;
        $gasolinePerUnit = $config['gas_vehicles']['gasoline_per_unit'][$powertrain] ?? 1;

        // The range: what the tank (and the gasoline tank of a car on gas) and the battery take it.
        $fuelRange = $fuelConsumption > 0
            ? (int) round($car['tank_capacity'] / $fuelConsumption * 100 + ($car['gasoline_tank_l'] > 0 ? $car['gasoline_tank_l'] / ($fuelConsumption * $gasolinePerUnit) * 100 : 0))
            : null;

        // Without charging at home: the more is driven, and the shorter the range, the more fast charging.
        $fastShare = null;
        $electricShare = null;

        if ($charges && $fuel === null) {
            $fastShare = $this->interpolate($config['fast_charging']['by_km_per_year'], $kmPerYear) * $config['fast_charging']['reference_range_km'] / max(1, $electricRange);
            $fastShare = round(max($config['fast_charging']['at_least'], min($config['fast_charging']['at_most'], $fastShare)), 2);
        } elseif ($charges) {
            $perDay = $kmPerYear / $config['plugin_hybrid']['driving_days_per_year'];
            $electricShare = round(min(1, $electricRange / max(1, $perDay)) * $config['plugin_hybrid']['charged_on'], 2);
        }

        $energyPerKm = match (true) {
            $fuelPerKm !== null => $fuelPerKm,
            $fastShare !== null => $electricity / 100 * ($fastShare * $price('Electricity DC') + (1 - $fastShare) * $price('Electricity AC')),
            $electricShare !== null => $electricShare * $electricity / 100 * $price('Electricity AC')
                + (1 - $electricShare) * $fuelConsumption / 100 * $price($fuel),
            default => $fuelConsumption / 100 * ((1 - $gasolineShare) * $price($fuel) + $gasolineShare * $gasolinePerUnit * $price('Unleaded 95 (E10)')),
        };

        if ($spec['adblue'] ?? false) {
            $energyPerKm += $config['adblue_per_100km'] / 100 * $price('Adblue');
        }

        $estimate = fn (string $field) => ['year' => (float) $car[$field], 'estimated' => true];
        $types = [
            ...($fuel !== null ? [$fuel] : []),
            ...($fastShare !== null ? ['Electricity AC', 'Electricity DC'] : []),
            ...($electricShare !== null ? ['Electricity AC'] : []),
        ];

        return [
            'prices' => $this->pricesOf($types, $prices),
            'powertrain' => $powertrain,
            'powertrainName' => trans('powertrains')[$powertrain]['name'] ?? $powertrain,
            'buildYear' => $car['build_year'],
            'powerHp' => $car['power_hp'],
            'priceNow' => $car['price_now_eur'],
            'range' => [
                'total' => (int) (($fuelRange ?? 0) + ($electricRange ?? 0)),
                'electric' => $electricRange,
                'fuel' => $fuelRange,
            ],
            'consumption' => [
                'fuel' => $fuelConsumption,
                'fuelUnit' => $fuel !== null ? trans('powertrains')[$powertrain]['consumption_unit'] ?? 'l/100km' : null,
                'electricity' => $electricity,
                'electricShare' => $electricShare,
                'fastShare' => $fastShare,
            ],
            'costs' => [
                'fuel' => ['year' => round($energyPerKm * $kmPerYear, 2), 'estimated' => $fuelPerKm === null],
                'road_tax' => $estimate('road_tax_per_year_eur'),
                'insurance' => $estimate('insurance_per_year_eur'),
                'maintenance' => $estimate('maintenance_per_year_eur'),
                'depreciation' => $estimate('depreciation_per_year_eur'),
            ],
        ];
    }

    /**
     * The prices a vehicle is calculated with, by the unit they are sold in;
     * estimated when no feed or refueling gave them.
     *
     * @param  list<string>  $types
     * @param  array<string, array{price: float, source: string, date: string|null}>  $prices
     * @return list<array{type: string, label: string, price: float, unit: string, estimated: bool}>
     */
    private function pricesOf(array $types, array $prices): array
    {
        $units = ['CNG' => 'kg', 'Hydrogen' => 'kg', 'Electricity AC' => 'kWh', 'Electricity DC' => 'kWh'];

        return array_values(array_map(fn (string $type) => [
            'type' => $type,
            'label' => trans('fuel_types')[$type] ?? $type,
            'price' => $prices[$type]['price'] ?? (float) config("powertrain_comparison.fallback_prices.{$type}", 0),
            'unit' => $units[$type] ?? 'l',
            'estimated' => ! in_array($prices[$type]['source'] ?? 'fallback', ['feed', 'refuelings'], true),
        ], array_unique($types)));
    }

    /**
     * The value at a point of a table, interpolated linearly between its
     * points, and the first or last value beyond them.
     *
     * @param  array<int, float>  $table
     */
    private function interpolate(array $table, float $at): float
    {
        ksort($table);
        $points = array_keys($table);

        if ($at <= $points[0]) {
            return $table[$points[0]];
        }

        foreach ($points as $index => $point) {
            $next = $points[$index + 1] ?? null;

            if ($next !== null && $at <= $next) {
                return $table[$point] + ($table[$next] - $table[$point]) * ($at - $point) / ($next - $point);
            }
        }

        return $table[end($points)];
    }
}
