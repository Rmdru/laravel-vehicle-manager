<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use App\Services\TripPlanner\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The United Kingdom: the large retailers publish the prices of their gas
 * stations as open data, at the request of the Competition and Markets
 * Authority. The prices are in pence, and turned into euros.
 */
class UkRetailers implements StationPrices
{
    /** The fields of a fuel in the feeds. */
    private const FUELS = ['e10' => 'E10', 'e5' => 'E5', 'super_plus' => 'E5', 'diesel' => 'B7'];

    /** The fuel types of the app, by field. */
    private const FUEL_TYPES = ['E10' => 'Unleaded 95 (E10)', 'E5' => 'Super Plus (E5)', 'B7' => 'Diesel'];

    public function country(): string
    {
        return 'GB';
    }

    public function near(float $latitude, float $longitude, string $fuel): array
    {
        $field = self::FUELS[$fuel] ?? null;
        $rate = $this->poundsPerEuro();

        if ($field === null || $rate === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $station) => isset($station['prices'][$field]) ? [
                'latitude' => $station['latitude'],
                'longitude' => $station['longitude'],
                // In pence.
                'price' => round($station['prices'][$field] / 100 / $rate, 3),
                'updated_at' => null,
                'fuel_type' => self::FUEL_TYPES[$field],
            ] : null,
            array_filter(
                $this->stations(),
                fn (array $station) => Geo::distance($latitude, $longitude, $station['latitude'], $station['longitude']) <= 25000,
            ),
        )));
    }

    /**
     * The gas stations of all retailers, read once an hour.
     *
     * @return list<array{latitude: float, longitude: float, prices: array<string, float>}>
     */
    private function stations(): array
    {
        return Cache::remember('trip-planner:prices:gb', now()->addHour(), function () {
            $stations = [];

            foreach (config('trip_planner.prices.uk_feeds') as $feed) {
                try {
                    $response = Http::timeout(20)->withUserAgent('Mozilla/5.0')->get($feed);
                } catch (Throwable $exception) {
                    Log::warning('UK fuel prices could not be read.', ['feed' => $feed, 'message' => $exception->getMessage()]);

                    continue;
                }

                foreach ((array) ($response->ok() ? $response->json('stations') : []) as $station) {
                    $lat = $station['location']['latitude'] ?? null;
                    $lon = $station['location']['longitude'] ?? null;
                    $prices = array_filter((array) ($station['prices'] ?? []), fn ($price) => is_numeric($price) && $price > 0);

                    if (is_numeric($lat) && is_numeric($lon) && $prices) {
                        $stations[] = ['latitude' => (float) $lat, 'longitude' => (float) $lon, 'prices' => array_map('floatval', $prices)];
                    }
                }
            }

            return $stations;
        });
    }

    /**
     * Pounds for a euro, by the reference rate of the European Central Bank.
     */
    private function poundsPerEuro(): ?float
    {
        return Cache::remember('trip-planner:gbp-rate', now()->addHours(12), function () {
            try {
                $xml = Http::timeout(10)->get(config('trip_planner.prices.ecb_rates_url'))->body();
            } catch (Throwable) {
                return null;
            }

            return preg_match("/currency='GBP' rate='([\\d.]+)'/", $xml, $match) ? (float) $match[1] : null;
        });
    }
}
