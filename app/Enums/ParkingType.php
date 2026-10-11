<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum ParkingType: string implements HasIcon, HasLabel
{
    case Street = 'street';
    case Lot = 'lot';
    case Garage = 'garage';
    case ParkAndRide = 'park_and_ride';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Street => __('Street'),
            self::Lot => __('Parking lot'),
            self::Garage => __('Parking garage'),
            self::ParkAndRide => __('P+R'),
            self::Company => __('Company'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Street => 'maki:parking-paid',
            self::Lot => 'fa7-solid:square-parking',
            self::Garage => 'maki:parking-garage',
            self::ParkAndRide => 'ic:baseline-train',
            self::Company => 'mdi:office-building',
        };
    }
}
