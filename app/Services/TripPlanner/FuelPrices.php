<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\FuelPrice;
use App\Services\AnwbFuelPriceService;
use App\Services\TripPlanner\Prices\Anwb;
use App\Services\TripPlanner\Prices\EControl;
use App\Services\TripPlanner\Prices\Minetur;
use App\Services\TripPlanner\Prices\PrixCarburants;
use App\Services\TripPlanner\Prices\StationPrices;
use App\Services\TripPlanner\Prices\Tankerkoenig;
use App\Services\TripPlanner\Prices\UkRetailers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The price of fuel at a gas station along a route: its own price where the
 * country publishes the prices of its gas stations, or else the average price
 * of the country, with a surcharge along the motorway.
 */
class FuelPrices
{
    /** The prices of the stations around a point are read per cell of this many degrees. */
    private const CELL = 0.3;

    /** A station of a price source this close to a gas station is the same one: the sources round their coordinates. */
    private const SAME_STATION = 300;

    /** The fuel codes of the ANWB, by fuel. */
    private const ANWB = ['e10' => 'EURO95', 'e5' => 'EURO95', 'super_plus' => 'EURO98', 'diesel' => 'DIESEL', 'lpg' => 'AUTOGAS'];

    /** @var array<string, StationPrices> */
    private array $sources = [];

    /** @var array<string, StationPrices> The sources that are tried when the first one has no price. */
    private array $fallbacks = [];

    /** @var array<int, array{price: float, source: string, updated_at: ?string, fuel_type: ?string}|null> The prices found, by place. */
    private array $known = [];

    /** Whether the prices of the stations are read anew, rather than taken from what was kept. */
    public bool $fresh = false;

    /** @var array<string, true> The cells read anew so far. */
    private array $refreshed = [];

    public function __construct()
    {
        foreach ([new Tankerkoenig, new PrixCarburants, new EControl, new Minetur, new UkRetailers] as $source) {
            $this->sources[$source->country()] = $source;
        }

        // The prices the ANWB shows, where it is allowed to use them.
        if (config('trip_planner.prices.anwb.enabled')) {
            foreach ((array) config('trip_planner.prices.anwb.countries') as $country) {
                isset($this->sources[$country])
                    ? $this->fallbacks[$country] = new Anwb($country)
                    : $this->sources[$country] = new Anwb($country);
            }
        }
    }

    /**
     * The fuel a fuel type of the app is, as the price sources know it.
     */
    public static function fuel(?string $fuelType): ?string
    {
        return match ($fuelType) {
            'Unleaded 95 (E10)' => 'e10',
            'Unleaded 95 (E5)', 'E85' => 'e5',
            'Super Plus (E5)', 'Super Plus (E10)', 'V-Power 100', 'Ultimate 102' => 'super_plus',
            'Diesel', 'Premium diesel' => 'diesel',
            'LPG' => 'lpg',
            default => null,
        };
    }

    /**
     * The price of the fuel of a vehicle at a place a stop can be made at.
     *
     * @param  array<string, mixed>  $candidate
     * @return array{price: float, source: string, updated_at: ?string, fuel_type: ?string}|null
     */
    public function of(array $candidate, VehicleRange $range): ?array
    {
        $id = (int) $candidate['poi_id'];

        if (array_key_exists($id, $this->known)) {
            return $this->known[$id];
        }

        $fuel = self::fuel($range->fuelType);
        $country = isset($candidate['country']) ? CountryCodes::iso2($candidate['country']) : null;

        if ($fuel === null || $country === null) {
            return $this->known[$id] = null;
        }

        $latitude = (float) ($candidate['fuel_latitude'] ?? $candidate['latitude']);
        $longitude = (float) ($candidate['fuel_longitude'] ?? $candidate['longitude']);
        $station = $this->station($country, $latitude, $longitude, $fuel);

        if ($station) {
            return $this->known[$id] = [
                'price' => $station['price'],
                'source' => 'station',
                'updated_at' => $station['updated_at'],
                'fuel_type' => $station['fuel_type'] ?? $range->fuelType,
            ];
        }

        $average = $this->average($country, (string) $range->fuelType, $fuel);

        if ($average === null) {
            return $this->known[$id] = null;
        }

        $surcharge = ($candidate['motorway'] ?? false)
            ? (float) config("trip_planner.prices.motorway_surcharge.{$country}", config('trip_planner.prices.default_motorway_surcharge'))
            : 0.0;

        return $this->known[$id] = ['price' => round($average + $surcharge, 3), 'source' => 'average', 'updated_at' => null, 'fuel_type' => $range->fuelType];
    }

    /**
     * The prices that were found, of a list of places.
     *
     * @param  list<int>  $ids
     * @return array<int, array{price: float, source: string, updated_at: ?string, fuel_type: ?string}>
     */
    public function known(array $ids): array
    {
        return array_filter(array_intersect_key($this->known, array_flip($ids)));
    }

    /**
     * @return array{price: float, updated_at: ?string, fuel_type?: string}|null
     */
    private function station(string $country, float $latitude, float $longitude, string $fuel): ?array
    {
        foreach (array_filter([$this->sources[$country] ?? null, $this->fallbacks[$country] ?? null]) as $source) {
            $station = $this->stationFrom($source, $country, $latitude, $longitude, $fuel);

            if ($station !== null) {
                return $station;
            }
        }

        return null;
    }

    /**
     * @return array{price: float, updated_at: ?string, fuel_type?: string}|null
     */
    private function stationFrom(StationPrices $source, string $country, float $latitude, float $longitude, string $fuel): ?array
    {
        $cellLat = round($latitude / self::CELL) * self::CELL;
        $cellLon = round($longitude / self::CELL) * self::CELL;

        $key = 'trip-planner:prices:'.class_basename($source).":{$country}:{$fuel}:{$cellLat}:{$cellLon}";

        // Read anew once, when the prices are refreshed.
        if ($this->fresh && ! isset($this->refreshed[$key])) {
            Cache::forget($key);
            $this->refreshed[$key] = true;
        }

        $stations = Cache::remember(
            $key,
            now()->addMinutes((int) config('trip_planner.prices.cache_minutes')),
            function () use ($source, $cellLat, $cellLon, $fuel, $country) {
                try {
                    return $source->near($cellLat, $cellLon, $fuel);
                } catch (Throwable $exception) {
                    Log::warning('Fuel prices could not be read.', ['country' => $country, 'message' => $exception->getMessage()]);

                    return [];
                }
            },
        );

        $nearest = null;
        $nearestDistance = self::SAME_STATION;

        foreach ($stations as $station) {
            $distance = Geo::distance($latitude, $longitude, $station['latitude'], $station['longitude']);

            if ($distance <= $nearestDistance) {
                $nearest = $station;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    /**
     * The average price of the fuel of a vehicle along a route: that of each
     * country it passes through, by the distance driven there. Null when the
     * price is known in none of them.
     */
    public function averageAlong(Route $route, VehicleRange $range): ?float
    {
        $fuel = self::fuel($range->fuelType);
        $sum = 0.0;
        $meters = 0.0;

        foreach ($fuel === null ? [] : $route->countries as $section) {
            $distance = ($route->distances[$section['end']] ?? 0) - ($route->distances[$section['start']] ?? 0);
            $price = $distance > 0 ? $this->average(CountryCodes::iso2($section['country']), (string) $range->fuelType, $fuel) : null;

            if ($price !== null) {
                $sum += $price * $distance;
                $meters += $distance;
            }
        }

        return $meters > 0 ? $sum / $meters : null;
    }

    /**
     * The average price of a fuel in a country: from the prices the app reads
     * every day, or else from the averages of the ANWB.
     */
    private function average(string $country, string $fuelType, string $fuel): ?float
    {
        return Cache::remember("trip-planner:average:{$country}:{$fuel}", now()->addHours(6), function () use ($country, $fuelType, $fuel) {
            $key = collect(config('countries'))->search(fn (array $config) => $config['iso_code'] === $country);

            if ($key !== false) {
                $price = FuelPrice::where('country', $key)
                    ->where('fuel_type', $fuelType)
                    ->where('date', '>=', now()->subDays(7))
                    ->latest('date')
                    ->value('price');

                if ($price !== null) {
                    return (float) $price;
                }
            }

            $anwb = Cache::remember('trip-planner:anwb', now()->addHours(12), fn () => (new AnwbFuelPriceService)->crawlAnwbFuelPrices());
            $price = $anwb[CountryCodes::iso3($country)][self::ANWB[$fuel]] ?? null;

            return $price !== null ? (float) $price : null;
        });
    }
}
