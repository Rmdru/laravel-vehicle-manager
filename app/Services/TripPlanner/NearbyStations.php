<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\Poi;

/**
 * The gas stations around a point with what a fuel costs there: the
 * cheapest near home for the fuel advice, the dashboard and the push of a
 * nearly empty tank, or the nearest to choose the one of a refueling.
 */
class NearbyStations
{
    /** Gas stations looked at around a point, at most: the nearest. */
    private const LOOKED_AT = 300;

    public function __construct(private readonly StationMap $map) {}

    /**
     * The cheapest gas stations with a price of a fuel type within a radius.
     *
     * @param  string|null  $country  Only those in this country, ISO 2.
     * @return list<array<string, mixed>>
     */
    public function cheapest(float $latitude, float $longitude, string $fuelType, ?float $radiusKm = null, int $limit = 3, ?string $country = null): array
    {
        return collect($this->around($latitude, $longitude, $fuelType, $radiusKm ?? (float) config('fuel_advice.nearby_km'), $country))
            ->filter(fn (array $station) => $station['price'] !== null)
            ->sortBy([['price', 'asc'], ['distance_km', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * The nearest gas stations, with a price of the fuel type where it is
     * known: to choose the one of a refueling.
     *
     * @return list<array<string, mixed>>
     */
    public function nearest(float $latitude, float $longitude, ?string $fuelType, float $radiusKm = 5, int $limit = 10): array
    {
        return collect($this->around($latitude, $longitude, $fuelType, $radiusKm))
            ->sortBy('distance_km')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * All gas stations with a price of a fuel type within a radius: for the
     * history of the prices near home.
     *
     * @return list<array<string, mixed>>
     */
    public function priced(float $latitude, float $longitude, string $fuelType, float $radiusKm): array
    {
        return collect($this->around($latitude, $longitude, $fuelType, $radiusKm))
            ->filter(fn (array $station) => $station['price'] !== null)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function around(float $latitude, float $longitude, ?string $fuelType, float $radiusKm, ?string $country = null): array
    {
        $dLat = $radiusKm / 111.32;
        $dLon = $radiusKm / (111.32 * max(0.1, cos(deg2rad($latitude))));

        $pois = Poi::query()
            ->where('kind', Poi::GAS_STATION)
            ->whereBetween('latitude', [$latitude - $dLat, $latitude + $dLat])
            ->whereBetween('longitude', [$longitude - $dLon, $longitude + $dLon])
            ->get()
            ->map(fn (Poi $poi) => ['poi' => $poi, 'meters' => Geo::distance($latitude, $longitude, (float) $poi->latitude, (float) $poi->longitude)])
            ->filter(fn (array $item) => $item['meters'] <= $radiusKm * 1000)
            ->filter(fn (array $item) => $country === null || $this->map->countryOf($item['poi']) === $country)
            ->sortBy('meters')
            ->take(self::LOOKED_AT);

        $prices = $fuelType === null ? [] : $this->map->pricesOf($pois->pluck('poi'), $fuelType);

        return $pois->map(function (array $item) use ($prices) {
            /** @var Poi $poi */
            $poi = $item['poi'];
            $price = $prices[$poi->id] ?? null;

            return [
                'id' => $poi->id,
                'name' => $poi->name ?? $poi->brand,
                'brand' => $poi->brand,
                'city' => $poi->tags['addr:city'] ?? null,
                'street' => trim(($poi->tags['addr:street'] ?? '').' '.($poi->tags['addr:housenumber'] ?? '')) ?: null,
                'country' => $this->map->countryOf($poi),
                'latitude' => (float) $poi->latitude,
                'longitude' => (float) $poi->longitude,
                'distance_km' => round($item['meters'] / 1000, 1),
                'price' => $price['price'] ?? null,
                'served_price' => $price['served_price'] ?? null,
                'fuel_type' => $price['fuel_type'] ?? null,
                'updated_at' => $price['updated_at'] ?? null,
                'poi' => $poi,
            ];
        })->values()->all();
    }
}
