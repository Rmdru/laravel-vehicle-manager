<?php

declare(strict_types=1);

namespace App\Pipelines\ImportFuelPrices;

use App\Enums\VehicleStatus;
use App\Models\FuelDetourAggregate;
use App\Models\FuelPrice;
use App\Models\Vehicle;
use App\Traits\VehicleStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * How far each vehicle can drive out of its way for the fuel prices abroad:
 * every drivable vehicle, and the one asked for by hand whatever its status.
 */
class CalculateFuelDetourAggregates
{
    use VehicleStats;

    /**
     * @param  string|null  $vehicleId  A vehicle to calculate the detours of, whatever its status.
     * @param  bool  $onlyThatVehicle  Leave out the drivable vehicles.
     */
    public function __construct(
        private readonly ?string $vehicleId = null,
        private readonly bool $onlyThatVehicle = false,
    ) {
        //
    }

    public function handle($data, $next): array
    {
        $countries = array_values(array_unique(array_column($data, 'country')));

        Vehicle::query()
            ->where(fn (Builder $query) => $query
                ->when(! $this->onlyThatVehicle, fn (Builder $query) => $query->where('status', VehicleStatus::Drivable->value))
                ->when($this->vehicleId !== null, fn (Builder $query) => $query->orWhere('id', $this->vehicleId)))
            ->chunkById(100, function ($vehicles) use ($data, $countries) {
                foreach ($vehicles as $vehicle) {
                    if (in_array($vehicle->powertrain, ['electricity', 'hydrogen'])) {
                        continue;
                    }

                    // The detours are worth it compared to the prices at home.
                    $home = $vehicle->fuelPriceHomeCountry($countries);

                    foreach ($data as $fuelPrice) {
                        // No detour is worth a fuel the vehicle cannot run on.
                        if ($fuelPrice['country'] === $home || ! $vehicle->suitableForFuel($fuelPrice['fuel_type'])) {
                            continue;
                        }

                        $fuelPriceRecord = FuelPrice::query()
                            ->where('country', $fuelPrice['country'])
                            ->where('fuel_type', $fuelPrice['fuel_type'])
                            ->whereDate('date', $fuelPrice['date'])
                            ->first('id');

                        if ($fuelPriceRecord === null) {
                            Log::channel('fuel-prices')->warning('FuelPrice record not found', [
                                'country' => $fuelPrice['country'],
                                'fuel_type' => $fuelPrice['fuel_type'],
                                'date' => $fuelPrice['date'],
                            ]);

                            continue;
                        }

                        $fuelPriceId = $fuelPriceRecord->id;

                        $avgFuelCostsPerKm = $this->calculateAverageFuelCostsPerKilometer($vehicle->id);
                        $avgTotalCostsPerKm = $this->calculateCostsPerKilometer(vehicle: $vehicle);

                        // Compared with the same fuel at home, or else with the regular petrol.
                        $comparisionFuelType = match ($fuelPrice['fuel_type']) {
                            'Unleaded 95 (E10)' => 'Unleaded 95 (E10)',
                            'Unleaded 95 (E5)' => 'Unleaded 95 (E10)',
                            'Super Plus (E5)' => 'Unleaded 95 (E10)',
                            default => $fuelPrice['fuel_type'],
                        };

                        $homePrices = fn (string $fuelType) => array_filter($data, function ($item) use ($fuelType, $home) {
                            return $item['country'] === $home && $item['fuel_type'] === $fuelType;
                        });

                        $homeFuelPrice = $homePrices($fuelPrice['fuel_type']) ?: $homePrices($comparisionFuelType);

                        if (empty($homeFuelPrice)) {
                            continue;
                        }

                        $priceDiff = ($homeFuelPrice[array_key_first($homeFuelPrice)]['price'] - $fuelPrice['price']) * $vehicle->tank_capacity;

                        $maxDetourOnlyFuelCosts = 0;
                        if ($avgFuelCostsPerKm > 0 && $priceDiff > 0) {
                            $maxDetourOnlyFuelCosts = $priceDiff / $avgFuelCostsPerKm;
                        }

                        $maxDetourTotalCosts = 0;
                        if ($avgTotalCostsPerKm > 0 && $priceDiff > 0) {
                            $maxDetourTotalCosts = $priceDiff / $avgTotalCostsPerKm;
                        }

                        FuelDetourAggregate::upsert(
                            [
                                'vehicle_id' => $vehicle->id,
                                'fuel_price_id' => $fuelPriceId,
                                'max_detour_only_fuel_costs' => $maxDetourOnlyFuelCosts,
                                'max_detour_all_costs' => $maxDetourTotalCosts,
                            ],
                            ['fuel_price_id', 'vehicle_id'],
                        );
                    }
                }
            });

        return $next($data);
    }
}
