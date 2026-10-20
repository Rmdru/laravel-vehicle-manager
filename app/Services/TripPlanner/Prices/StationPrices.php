<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

/**
 * The prices at the gas stations of a country, from a public source.
 */
interface StationPrices
{
    /** The ISO 2 code of the country. */
    public function country(): string;

    /**
     * The gas stations around a point with the price of a fuel at each, and
     * the fuel type of the app the price is of: that can be another one than
     * asked for, when a source has no price of it.
     *
     * @param  string  $fuel  One of e10, e5, super_plus, diesel or lpg.
     * @return list<array{latitude: float, longitude: float, price: float, updated_at: ?string, fuel_type?: string}>
     */
    public function near(float $latitude, float $longitude, string $fuel): array;
}
