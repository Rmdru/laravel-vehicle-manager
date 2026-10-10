<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Vehicle;

class CurrentVehicle
{
    private static ?Vehicle $vehicle = null;

    public static function set(?Vehicle $vehicle): void
    {
        self::$vehicle = $vehicle;
    }

    public static function get(): ?Vehicle
    {
        return self::$vehicle;
    }
}
