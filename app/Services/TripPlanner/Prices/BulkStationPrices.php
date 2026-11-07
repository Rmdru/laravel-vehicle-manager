<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

/**
 * A source that publishes the prices of all gas stations of a country at
 * once: imported every hour into station_prices, see StationPriceImporter.
 */
interface BulkStationPrices
{
    /** The ISO 2 code of the country. */
    public function country(): string;

    /**
     * Every gas station with its prices: per fuel (e10, e5, super_plus,
     * diesel, lpg or cng) the fuel type of the app it is, and self service or
     * served where the source tells.
     *
     * @return iterable<array{id: string, name: ?string, latitude: float, longitude: float, prices: list<array{fuel: string, fuel_type: string, price: float, service: ?string, updated_at: ?string}>}>
     */
    public function all(): iterable;
}
