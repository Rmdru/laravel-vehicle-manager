<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VehicleStatus;
use App\Models\StationPriceHistory;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripPlanner\NearbyStations;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps what the fuel of each vehicle costs at the gas stations near the home
 * of its owner, a few times a day: so that later a refueling can be compared
 * with what was cheapest nearby that day. Only near homes, and only the fuel
 * types refueled with: a small history, not one of every gas station.
 */
class StationPriceRecorder
{
    public function __construct(
        private readonly NearbyStations $nearby,
        private readonly FuelComparison $comparison,
    ) {
        //
    }

    /**
     * @return int The prices kept.
     */
    public function record(): int
    {
        $kept = 0;

        User::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereHas('vehicles', fn ($query) => $query->where('status', VehicleStatus::Drivable->value))
            ->with(['vehicles' => fn ($query) => $query->where('status', VehicleStatus::Drivable->value)])
            ->chunkById(50, function ($users) use (&$kept) {
                foreach ($users as $user) {
                    $fuelTypes = $user->vehicles
                        ->map(fn (Vehicle $vehicle) => $this->comparison->fuelType($vehicle))
                        ->filter()
                        ->unique();

                    foreach ($fuelTypes as $fuelType) {
                        try {
                            $kept += $this->recordAround($user->latitude, $user->longitude, $fuelType);
                        } catch (Throwable $exception) {
                            Log::warning('The prices near home could not be kept.', ['user' => $user->id, 'message' => $exception->getMessage()]);
                        }
                    }
                }
            });

        return $kept;
    }

    private function recordAround(float $latitude, float $longitude, string $fuelType): int
    {
        $stations = $this->nearby->priced($latitude, $longitude, $fuelType, (float) config('fuel_advice.nearby_km'));
        $date = today()->toDateString();

        if ($stations === []) {
            return 0;
        }

        // Already kept today, near another home: the snapshot adds to it.
        $today = StationPriceHistory::query()
            ->whereIn('poi_id', array_column($stations, 'id'))
            ->where('fuel_type', $fuelType)
            ->whereDate('date', $date)
            ->get()
            ->keyBy('poi_id');

        foreach ($stations as $station) {
            $price = round((float) $station['price'], 3);
            $kept = $today->get($station['id']);

            if ($kept !== null && $kept->updated_at?->gt(now()->subMinutes(30))) {
                // This snapshot was made already, near the home of another owner.
                continue;
            }

            StationPriceHistory::query()->updateOrCreate(
                ['poi_id' => $station['id'], 'fuel_type' => $fuelType, 'date' => $date],
                [
                    'name' => $station['name'],
                    'country' => $station['country'],
                    'latitude' => $station['latitude'],
                    'longitude' => $station['longitude'],
                    'lowest_price' => min($price, $kept->lowest_price ?? $price),
                    'highest_price' => max($price, $kept->highest_price ?? $price),
                    'last_price' => $price,
                    'samples' => ($kept->samples ?? 0) + 1,
                ],
            );
        }

        return count($stations);
    }
}
