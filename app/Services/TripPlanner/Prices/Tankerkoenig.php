<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use Illuminate\Support\Facades\Http;

/**
 * Germany: the prices every gas station reports to the Markttransparenzstelle.
 */
class Tankerkoenig implements StationPrices
{
    private const FUELS = ['e10' => 'e10', 'e5' => 'e5', 'super_plus' => 'e5', 'diesel' => 'diesel'];

    public function country(): string
    {
        return 'DE';
    }

    public function near(float $latitude, float $longitude, string $fuel): array
    {
        $key = config('tankerkoenig.api_key');

        if (! $key || ! isset(self::FUELS[$fuel])) {
            return [];
        }

        $stations = Http::timeout(10)->get(config('tankerkoenig.list_url'), [
            'lat' => round($latitude, 4),
            'lng' => round($longitude, 4),
            // The largest radius the API allows.
            'rad' => 25,
            'sort' => 'dist',
            'type' => 'all',
            'apikey' => $key,
        ])->throw()->json('stations') ?? [];

        return collect($stations)
            ->filter(fn (array $station) => is_numeric($station[self::FUELS[$fuel]] ?? null))
            ->map(fn (array $station) => [
                'latitude' => (float) $station['lat'],
                'longitude' => (float) $station['lng'],
                'price' => (float) $station[self::FUELS[$fuel]],
                'updated_at' => null,
                // Super Plus is not reported: the price of E5 is.
                'fuel_type' => ['e10' => 'Unleaded 95 (E10)', 'e5' => 'Unleaded 95 (E5)', 'diesel' => 'Diesel'][self::FUELS[$fuel]],
            ])
            ->values()
            ->all();
    }
}
