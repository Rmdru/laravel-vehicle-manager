<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum ServiceType: string implements HasIcon, HasLabel
{
    case TirePressure = 'tire_pressure';
    case RoadsideAssistance = 'roadside_assistance';
    case Salvage = 'salvage';
    case Coffee = 'coffee';
    case Restaurant = 'restaurant';
    case Shop = 'store';
    case Toilet = 'toilet';
    case Hotel = 'hotel';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::TirePressure => __('Tire pressure'),
            self::RoadsideAssistance => __('Roadside assistance'),
            self::Salvage => __('Salvage'),
            self::Coffee => __('Coffee'),
            self::Restaurant => __('Restaurant'),
            self::Shop => __('Shop'),
            self::Toilet => __('Toilet'),
            self::Hotel => __('Hotel'),
            self::Other => __('Other'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::TirePressure => 'ic:round-tire-repair',
            self::RoadsideAssistance => 'ic:baseline-phone',
            self::Salvage => 'mdi:tow-truck',
            self::Coffee => 'mdi:coffee',
            self::Restaurant => 'ic:round-restaurant-menu',
            self::Shop => 'ic:baseline-store',
            self::Toilet => 'fa7-solid:restroom',
            self::Hotel => 'ic:round-local-hotel',
            self::Other => '',
        };
    }
}
