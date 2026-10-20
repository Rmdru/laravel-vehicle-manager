<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use App\Services\TripPlanner\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Spain: the ministry publishes the prices of all gas stations in one file.
 */
class Minetur implements StationPrices
{
    private const FUELS = [
        'e10' => 'Precio Gasolina 95 E10',
        'e5' => 'Precio Gasolina 95 E5',
        'super_plus' => 'Precio Gasolina 98 E5',
        'diesel' => 'Precio Gasoleo A',
        'lpg' => 'Precio Gases licuados del petróleo',
    ];

    /** The fuel types of the app, by fuel. */
    private const FUEL_TYPES = [
        'e10' => 'Unleaded 95 (E10)',
        'e5' => 'Unleaded 95 (E5)',
        'super_plus' => 'Super Plus (E5)',
        'diesel' => 'Diesel',
        'lpg' => 'LPG',
    ];

    public function country(): string
    {
        return 'ES';
    }

    public function near(float $latitude, float $longitude, string $fuel): array
    {
        // Most gas stations in Spain have no E10: then the E5 price applies.
        $columns = $fuel === 'e10' ? [self::FUELS['e10'], self::FUELS['e5']] : [self::FUELS[$fuel] ?? null];

        foreach (array_filter($columns) as $column) {
            $stations = array_values(array_filter(
                $this->stations($column),
                fn (array $station) => Geo::distance($latitude, $longitude, $station['latitude'], $station['longitude']) <= 25000,
            ));

            if ($stations) {
                $fuelType = array_search($column, self::FUELS, true);

                return array_map(fn (array $station) => [...$station, 'fuel_type' => self::FUEL_TYPES[$fuelType]], $stations);
            }
        }

        return [];
    }

    /**
     * All gas stations with a price of a fuel, read once every few hours.
     *
     * @return list<array{latitude: float, longitude: float, price: float, updated_at: ?string}>
     */
    private function stations(string $column): array
    {
        return Cache::remember('trip-planner:prices:es:'.md5($column), now()->addHours(4), function () use ($column) {
            $number = fn (mixed $value) => is_string($value) && $value !== '' ? (float) str_replace(',', '.', $value) : null;
            $stations = [];

            $response = Http::timeout(60)
                ->accept('application/json')
                ->get(config('trip_planner.prices.spain_url'))
                ->throw();

            foreach ($response->json('ListaEESSPrecio') ?? [] as $station) {
                $price = $number($station[$column] ?? null);
                $lat = $number($station['Latitud'] ?? null);
                $lon = $number($station['Longitud (WGS84)'] ?? null);

                if ($price && $lat && $lon) {
                    $stations[] = ['latitude' => $lat, 'longitude' => $lon, 'price' => $price, 'updated_at' => null];
                }
            }

            return $stations;
        });
    }
}
