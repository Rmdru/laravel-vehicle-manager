<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum ProductCategory: string implements HasIcon, HasLabel
{
    case EngineOil = 'engine_oil';
    case Coolant = 'coolant';
    case BrakeFluid = 'brake_fluid';
    case WasherFluid = 'washer_fluid';
    case AdBlue = 'adblue';
    case DeIcer = 'de_icer';
    case Cleaning = 'cleaning';
    case Part = 'part';
    case Accessory = 'accessory';
    case Safety = 'safety';
    case Other = 'other';

    /** The fluids: topped up, in liters. */
    public const FLUIDS = [self::EngineOil, self::Coolant, self::BrakeFluid, self::WasherFluid, self::AdBlue];

    public function getLabel(): string
    {
        return match ($this) {
            self::EngineOil => __('Engine oil'),
            self::Coolant => __('Coolant'),
            self::BrakeFluid => __('Brake fluid'),
            self::WasherFluid => __('Washer fluid'),
            self::AdBlue => __('AdBlue'),
            self::DeIcer => __('De-icer'),
            self::Cleaning => __('Detailing products'),
            self::Part => __('Car part'),
            self::Accessory => __('Accessory'),
            self::Safety => __('Safety equipment'),
            self::Other => __('Other'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::EngineOil => 'mdi:oil',
            self::Coolant => 'mdi:car-coolant-level',
            self::BrakeFluid => 'mdi:car-brake-fluid-level',
            self::WasherFluid => 'mdi:wiper-wash',
            self::AdBlue => 'mdi:fuel',
            self::DeIcer => 'mdi:car-defrost-front',
            self::Cleaning => 'mdi:spray-bottle',
            self::Part => 'mdi:car-cog',
            self::Accessory => 'mdi:car-seat',
            self::Safety => 'mdi:hazard-lights',
            self::Other => '',
        };
    }

    public function isFluid(): bool
    {
        return in_array($this, self::FLUIDS, true);
    }
}
