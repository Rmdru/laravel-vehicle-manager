<?php

declare(strict_types=1);

namespace App\Traits;

use App\Support\CurrentVehicle;

trait FuelTypeOptions
{
    public function getFuelTypeOptions(): array
    {
        $vehicle = CurrentVehicle::get();
        $fuelTypes = trans('fuel_types');
        $fuelTypeOptions = [];

        foreach ($vehicle->fuel_types as $value) {
            $fuelTypeOptions[$value] = $fuelTypes[$value];
        }

        return $fuelTypeOptions;
    }
}
