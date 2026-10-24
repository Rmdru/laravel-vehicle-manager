<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\EnergyPriceEstimator;
use App\Ai\ApiKeys;
use App\Models\FuelPrice;
use App\Models\Insurance;
use App\Models\Refueling;
use App\Models\Tax;
use App\Models\Vehicle;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What the comparison of the powertrains knows from the app itself: how the
 * vehicle is driven, what it costs now, and the prices of every kind of
 * energy. The AI model only estimates what is not known here.
 */
class PowertrainComparisonFacts
{
    /** Days a price of a feed is recent enough. */
    private const RECENT_PRICE_DAYS = 30;

    /** Months of refuelings of the owner a price is the average of, when no feed has it. */
    private const OWN_PRICE_MONTHS = 6;

    /** Years of maintenance the costs of the vehicle itself are the average of, at most. */
    private const MAINTENANCE_YEARS = 3;

    /** The distance a year of refuelings has to cover to tell what the fuel costs by the kilometer. */
    public const FUEL_COSTS_AT_LEAST_KM = 1000;

    /** The estimates of the model, by the fuel type they are the price of. */
    private const ESTIMATES = [
        'Electricity AC' => 'electricity_ac_kwh',
        'Electricity DC' => 'electricity_dc_kwh',
        'CNG' => 'cng_kg',
        'Hydrogen' => 'hydrogen_kg',
        'Adblue' => 'adblue_l',
    ];

    /**
     * How the vehicle is driven and what it costs, from its records. A value
     * is null when the records do not tell.
     *
     * @return array{km_per_year: int, km_estimated: bool, consumption: float|null, fuel_amounts: array<string, float>, fuel_distance: int, road_tax_per_year: float|null, insurance_per_year: float|null, insurance_cover: string|null, maintenance_per_year: float|null}
     */
    public function usage(Vehicle $vehicle): array
    {
        $refuelings = $vehicle->refuelings()
            ->whereDate('date', '>=', today()->subYear())
            ->orderBy('date')
            ->get(['date', 'fuel_type', 'amount', 'mileage_begin', 'mileage_end', 'fuel_consumption']);

        $distance = (int) $refuelings->sum(fn (Refueling $refueling) => max(0, (int) $refueling->mileage_end - (int) $refueling->mileage_begin));
        [$kmPerYear, $estimated] = $this->kmPerYear($vehicle, $refuelings, $distance);
        $consumption = $refuelings->pluck('fuel_consumption')->filter(fn ($value) => (float) $value > 0)->avg()
            ?? $vehicle->refuelings()->where('fuel_consumption', '>', 0)->latest('date')->limit(20)->avg('fuel_consumption');
        $insurance = $this->current($vehicle->insurances()->getQuery());
        $tax = $this->current($vehicle->taxes()->getQuery());

        return [
            'km_per_year' => $kmPerYear,
            'km_estimated' => $estimated,
            'consumption' => $consumption ? round((float) $consumption, 2) : null,
            'fuel_amounts' => $refuelings
                ->groupBy(fn (Refueling $refueling) => (string) $this->scalar($refueling->fuel_type))
                ->map(fn (Collection $refuelings) => round((float) $refuelings->sum('amount'), 2))
                ->filter(fn (float $amount, string $type) => $amount > 0 && $type !== '')
                ->all(),
            'fuel_distance' => $distance,
            // The amounts are by the month.
            'road_tax_per_year' => $tax && (float) $tax->price > 0 ? round((float) $tax->price * 12, 2) : null,
            'insurance_per_year' => $insurance && (float) $insurance->price > 0 ? round((float) $insurance->price * 12, 2) : null,
            'insurance_cover' => $insurance ? (string) $this->scalar($insurance->type) : null,
            'maintenance_per_year' => $this->maintenancePerYear($vehicle),
        ];
    }

    /**
     * The kilometers a year: of the refuelings of the past year, else of the
     * mileage since the purchase, else the default (an estimate).
     *
     * @param  Collection<int, Refueling>  $refuelings
     * @return array{int, bool}
     */
    private function kmPerYear(Vehicle $vehicle, Collection $refuelings, int $distance): array
    {
        if ($distance > 0) {
            $months = max(1, (int) $refuelings->first()->date->diffInMonths(today()));

            return [(int) round($distance / min(12, $months) * 12), false];
        }

        $driven = (int) $vehicle->mileage_latest - (int) $vehicle->mileage_start;
        $years = $vehicle->purchase_date ? $vehicle->purchase_date->diffInDays(today()) / 365 : 0;

        if ($driven > 0 && $years >= 0.5) {
            return [(int) round($driven / $years), false];
        }

        return [(int) config('powertrain_comparison.default_km_per_year'), true];
    }

    /**
     * The insurance or road tax that runs today.
     */
    private function current($query): Insurance|Tax|null
    {
        return $query
            ->whereDate('start_date', '<=', today())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->latest('start_date')
            ->first();
    }

    /**
     * What maintenance cost a year, over the last years the vehicle was
     * owned. Null for less than a year, or when on average less than one
     * paid maintenance a year was recorded: then the records are not complete,
     * and the costs would look lower than they are.
     */
    private function maintenancePerYear(Vehicle $vehicle): ?float
    {
        $since = today()->subYears(self::MAINTENANCE_YEARS)->max($vehicle->purchase_date ?? today());
        $years = $since->diffInDays(today()) / 365;

        if ($years < 1) {
            return null;
        }

        $paid = $vehicle->maintenances()->whereDate('date', '>=', $since)->where('total_price', '>', 0)->get(['total_price']);

        return $paid->count() >= floor($years) ? round((float) $paid->sum('total_price') / $years, 2) : null;
    }

    /**
     * The price of each kind of energy, by fuel type: of the feed of the home
     * country, else of the refuelings of the owner, else as estimated, else
     * the fallback of the configuration.
     *
     * @param  list<string>  $types  Fuel types of the refuelings, next to those of the configuration.
     * @return array<string, array{price: float, source: string, date: string|null}>
     */
    public function prices(Vehicle $vehicle, array $types = []): array
    {
        $types = array_values(array_unique([...array_keys(config('powertrain_comparison.fallback_prices')), ...$types]));
        $recent = FuelPrice::query()
            ->whereIn('fuel_type', $types)
            ->whereDate('date', '>=', today()->subDays(self::RECENT_PRICE_DAYS))
            ->orderBy('date')
            ->get(['country', 'fuel_type', 'date', 'price']);
        $home = $vehicle->fuelPriceHomeCountry(array_values(array_unique($recent->pluck('country')->all())));
        $feed = $recent->where('country', $home)->keyBy('fuel_type');
        $own = Refueling::query()
            ->whereIn('vehicle_id', $vehicle->user?->vehicles()->pluck('id') ?? [$vehicle->id])
            ->whereIn('fuel_type', $types)
            ->where('unit_price', '>', 0)
            ->whereDate('date', '>=', today()->subMonths(self::OWN_PRICE_MONTHS))
            ->get(['fuel_type', 'unit_price'])
            ->groupBy(fn (Refueling $refueling) => (string) $this->scalar($refueling->fuel_type))
            ->map(fn (Collection $refuelings) => (float) $refuelings->avg('unit_price'));
        $estimates = $this->estimates($vehicle->country_registration ?: 'netherlands')['prices'] ?? [];
        $fallbacks = config('powertrain_comparison.fallback_prices');
        $bounds = config('powertrain_comparison.price_bounds');
        $plausible = fn (string $type, ?float $price) => $price !== null && $price > 0
            && (! isset($bounds[$type]) || ($price >= $bounds[$type][0] && $price <= $bounds[$type][1]));

        return collect($types)->mapWithKeys(function (string $type) use ($feed, $own, $estimates, $fallbacks, $plausible) {
            $estimate = (float) ($estimates[self::ESTIMATES[$type] ?? ''] ?? 0);

            return [$type => match (true) {
                $feed->has($type) => ['price' => (float) $feed[$type]->price, 'source' => 'feed', 'date' => $feed[$type]->date->toDateString()],
                $plausible($type, $own->get($type)) => ['price' => round($own[$type], 3), 'source' => 'refuelings', 'date' => null],
                $plausible($type, $estimate) => ['price' => $estimate, 'source' => 'estimate', 'date' => null],
                default => ['price' => (float) ($fallbacks[$type] ?? $fallbacks['Unleaded 95 (E10)']), 'source' => 'fallback', 'date' => null],
            }];
        })->all();
    }

    /**
     * The estimated prices of a country, with the day they were estimated;
     * empty when they never were.
     *
     * @return array{prices?: array<string, float>, at?: string}
     */
    public function estimates(string $country): array
    {
        return Cache::get($this->estimatesKey($country), []);
    }

    /**
     * Has the prices of a country estimated again when they are old. Whether
     * the model was asked.
     *
     * @throws \Throwable When the AI model gives no answer.
     */
    public function estimatePrices(string $country): bool
    {
        $at = $this->estimates($country)['at'] ?? null;

        if ($at !== null && Carbon::parse($at)->gt(today()->subDays(config('powertrain_comparison.prices_refresh_after')))) {
            return false;
        }

        $estimator = fn (?string $key) => new EnergyPriceEstimator($key);
        $response = ApiKeys::ask(config('powertrain_comparison.provider'), $estimator, $estimator(null)->question($country));

        Cache::forever($this->estimatesKey($country), [
            // Leaves out what is not sold there, or makes no sense.
            'prices' => collect(self::ESTIMATES)
                ->mapWithKeys(fn (string $key) => [$key => round((float) ($response[$key] ?? 0), 3)])
                ->filter(fn (float $price) => $price > 0 && $price < 50)
                ->all(),
            'at' => today()->toDateString(),
        ]);

        return true;
    }

    private function estimatesKey(string $country): string
    {
        return "powertrain-comparison:prices:{$country}";
    }

    /**
     * The value of an enum, or the value itself: not every column is cast.
     */
    private function scalar(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
