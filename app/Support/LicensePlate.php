<?php

declare(strict_types=1);

namespace App\Support;

class LicensePlate
{
    /**
     * The license plate without spaces, dashes and pluses, in capitals.
     */
    public static function normalize(string $licensePlate): string
    {
        return str_replace([' ', '-', '+'], '', strtoupper($licensePlate));
    }
}
