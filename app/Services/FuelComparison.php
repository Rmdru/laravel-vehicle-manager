<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poi;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Services\TripPlanner\FuelPrices;
use App\Services\TripPlanner\NearbyStations;
use App\Services\TripPlanner\StationMap;
use App\Traits\VehicleStats;
use Illuminate\Support\Facades\Cache;

/**
 * The gas stations near the home of the owner compared with the one the
 * vehicle usually refuels at: what the cheapest cost, how much less that is,
 * and up to how far a detour for it pays off. For the dashboard, the push of
 * a nearly empty tank and the predictions.
 */
class FuelComparison
{
    use VehicleStats;

    /** Visits in the past year a gas station needs to be the usual one. */
    private const USUAL_VISITS = 2;

    /** Minutes the gas stations near home are kept: the prices are of half an hour too. */
    private const CACHE_MINUTES = 30;

    public function __construct(private readonly NearbyStations $nearby) {}

    /**
     * The cheapest gas stations near home for the fuel of the vehicle, with
     * the usual one to compare with. Null without a home on the map, a fuel
     * type with a price per gas station, or a gas station with a price.
     *
     * @param  string|null  $fuelType  Another fuel type than the one of the vehicle, such as chosen on the dashboard.
     * @return array{fuel_type: string, stations: list<array<string, mixed>>, usual: array{name: string, price: float|null}|null}|null
     */
    public function nearHome(Vehicle $vehicle, ?string $fuelType = null): ?array
    {
        $user = $vehicle->user;
        $fuelType ??= $this->fuelType($vehicle);

        if ($user?->latitude === null || $user->longitude === null || $fuelType === null) {
            return null;
        }

        return Cache::remember("fuel-comparison:{$vehicle->id}:{$fuelType}", now()->addMinutes(self::CACHE_MINUTES), function () use ($vehicle, $user, $fuelType) {
            $around = $this->nearby->priced($user->latitude, $user->longitude, $fuelType, (float) config('fuel_advice.nearby_km'));

            if ($around === []) {
                return null;
            }

            $usual = $this->usual($vehicle, $around);
            $cheapest = collect($around)->sortBy([['price', 'asc'], ['distance_km', 'asc']])->take((int) config('fuel_advice.nearby_stations'));

            return [
                'fuel_type' => $fuelType,
                'usual' => $usual,
                'stations' => $cheapest->map(fn (array $station) => [
                    ...array_diff_key($station, ['poi' => true]),
                    // Less (negative) or more than at the usual gas station, per liter.
                    'difference' => $usual['price'] !== null ? round($station['price'] - $usual['price'], 3) : null,
                    'detour_km' => $usual['price'] !== null ? $this->detourKm($vehicle, $usual['price'] - $station['price']) : null,
                ])->values()->all(),
            ];
        });
    }

    /**
     * The fuel type the vehicle is refueled with most of late, or else the
     * first of the vehicle; null for one without a price per gas station.
     */
    public function fuelType(Vehicle $vehicle): ?string
    {
        $refueled = Refueling::query()
            ->where('vehicle_id', $vehicle->id)
            ->newest()
            ->limit(10)
            ->pluck('fuel_type')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $type = $refueled ?? collect($vehicle->fuel_types ?? [])->first();

        return $type !== null && FuelPrices::fuel($type) !== null ? $type : null;
    }

    /**
     * What the station map needs to tell of a gas station how much less it is
     * than the usual one, and up to how far a detour for it pays off. Null
     * without a usual gas station whose price is known.
     *
     * @return array{fuel_type: string, usual: string, price: float, tank: float, costs_per_km: float}|null
     */
    public function forMap(Vehicle $vehicle): ?array
    {
        $near = $this->nearHome($vehicle);
        $costsPerKilometer = $this->calculateAverageFuelCostsPerKilometer($vehicle->id);

        if ($near === null || ($near['usual']['price'] ?? null) === null || ! $vehicle->tank_capacity || $costsPerKilometer <= 0) {
            return null;
        }

        return [
            'fuel_type' => $near['fuel_type'],
            'usual' => $near['usual']['name'],
            'price' => $near['usual']['price'],
            'tank' => (float) $vehicle->tank_capacity,
            'costs_per_km' => round($costsPerKilometer, 4),
        ];
    }

    /**
     * Up to how many kilometers one way a detour for a gas station that is
     * cheaper by so much per liter pays off, for a full tank: the savings
     * against the fuel to get there and back. Null when it is not cheaper.
     */
    public function detourKm(Vehicle $vehicle, float $cheaperPerLiter): ?int
    {
        $costsPerKilometer = $this->calculateAverageFuelCostsPerKilometer($vehicle->id);

        if ($cheaperPerLiter <= 0 || ! $vehicle->tank_capacity || $costsPerKilometer <= 0) {
            return null;
        }

        return (int) floor($cheaperPerLiter * $vehicle->tank_capacity / (2 * $costsPerKilometer));
    }

    /**
     * The gas station the vehicle refuels at most, with what it costs now as
     * far as it is near home: by the gas station chosen for a refueling, or
     * by the name written down.
     *
     * @param  list<array<string, mixed>>  $around
     * @return array{name: string, price: float|null}|null
     */
    private function usual(Vehicle $vehicle, array $around): ?array
    {
        $refuelings = Refueling::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('date', '>=', today()->subYear())
            ->whereNotNull('gas_station')
            ->get(['gas_station', 'poi_id']);

        $usual = $refuelings
            ->groupBy(fn (Refueling $refueling) => $refueling->poi_id !== null ? 'poi:'.$refueling->poi_id : 'name:'.mb_strtolower(trim((string) $refueling->gas_station)))
            ->filter(fn ($visits) => $visits->count() >= self::USUAL_VISITS)
            ->sortByDesc(fn ($visits) => $visits->count())
            ->first();

        if ($usual === null) {
            return null;
        }

        $poiId = $usual->first()->poi_id;
        $name = (string) $usual->first()->gas_station;
        $station = collect($around)->first(fn (array $station) => $poiId !== null
            ? $station['id'] === $poiId
            : $station['poi'] instanceof Poi && StationMap::isNamed($station['poi'], $name));

        return ['name' => $name, 'price' => $station['price'] ?? null];
    }
}
