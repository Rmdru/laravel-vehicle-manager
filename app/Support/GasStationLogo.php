<?php

declare(strict_types=1);

namespace App\Support;

class GasStationLogo
{
    /**
     * The URL of the logo of a gas station. Its brand is the first word of its name.
     */
    public static function url(?string $gasStation): string
    {
        $logos = config('refuelings.gas_station_logos');
        $brand = (string) str((string) $gasStation)->lower()->explode(' ')->first();

        return asset('images/gas-stations/'.($logos[$brand] ?? $logos['default']));
    }
}
