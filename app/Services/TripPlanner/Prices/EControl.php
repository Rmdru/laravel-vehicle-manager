<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use Illuminate\Support\Facades\Http;

/**
 * Austria: E-Control tells the prices of the cheapest gas stations around a point.
 */
class EControl implements StationPrices
{
    private const FUELS = ['e10' => 'SUP', 'e5' => 'SUP', 'super_plus' => 'SUP', 'diesel' => 'DIE', 'lpg' => 'GAS'];

    public function country(): string
    {
        return 'AT';
    }

    public function near(float $latitude, float $longitude, string $fuel): array
    {
        $stations = Http::timeout(10)->get(config('trip_planner.prices.austria_url'), [
            'latitude' => round($latitude, 4),
            'longitude' => round($longitude, 4),
            'fuelType' => self::FUELS[$fuel] ?? 'SUP',
            'includeClosed' => 'false',
        ])->throw()->json() ?? [];

        return collect($stations)
            ->filter(fn (array $station) => isset($station['prices'][0]['amount'], $station['location']['latitude']))
            ->map(fn (array $station) => [
                'latitude' => (float) $station['location']['latitude'],
                'longitude' => (float) $station['location']['longitude'],
                'price' => (float) $station['prices'][0]['amount'],
                'updated_at' => null,
                'fuel_type' => ['SUP' => 'Unleaded 95 (E5)', 'DIE' => 'Diesel', 'GAS' => 'LPG'][self::FUELS[$fuel] ?? 'SUP'],
            ])
            ->values()
            ->all();
    }
}
