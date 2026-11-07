<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use App\Models\StationPrice;
use App\Services\TripPlanner\Geo;

/**
 * The prices of a country as they were imported into station_prices: for a
 * country whose source can only be read in full, such as Italy. Self service
 * is the price; the served one comes along where the source tells it.
 */
class StoredPrices implements StationPrices
{
    /** The fuels asked for, and what to take when a station has none of it. */
    private const FALLBACK = ['e10' => ['e10', 'e5'], 'e5' => ['e5', 'e10']];

    public function __construct(private readonly string $country) {}

    public function country(): string
    {
        return $this->country;
    }

    /**
     * @return list<array{latitude: float, longitude: float, price: float, updated_at: ?string, fuel_type?: string, served_price?: float|null}>
     */
    public function near(float $latitude, float $longitude, string $fuel): array
    {
        // About 25 km around the point.
        $rows = StationPrice::query()
            ->where('country', $this->country)
            ->whereIn('fuel', self::FALLBACK[$fuel] ?? [$fuel])
            ->whereBetween('latitude', [$latitude - 0.23, $latitude + 0.23])
            ->whereBetween('longitude', [$longitude - 0.35, $longitude + 0.35])
            ->get()
            ->filter(fn (StationPrice $row) => Geo::distance($latitude, $longitude, $row->latitude, $row->longitude) <= 25000);

        return $rows->groupBy('source_id')
            ->map(function ($prices) use ($fuel) {
                // The fuel asked for, if the station has it.
                $fuels = self::FALLBACK[$fuel] ?? [$fuel];
                $prices = $prices->sortBy(fn (StationPrice $row) => array_search($row->fuel, $fuels, true));
                $first = $prices->first();
                $same = $prices->where('fuel', $first->fuel);
                $self = $same->firstWhere('service', '!=', StationPrice::SERVED) ?? $same->first();
                $served = $same->firstWhere('service', StationPrice::SERVED);

                return [
                    'latitude' => $first->latitude,
                    'longitude' => $first->longitude,
                    'price' => $self->price,
                    'updated_at' => $self->reported_at?->toDateTimeString(),
                    'fuel_type' => $self->fuel_type,
                    'served_price' => $served !== null && $served !== $self ? $served->price : null,
                ];
            })
            ->values()
            ->all();
    }
}
