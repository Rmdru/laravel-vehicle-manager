<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum RefuelingClimateControl: string implements HasIcon, HasLabel
{
    case Automatically = 'automatically';
    case Airco = 'airco';
    case Heater = 'heater';
    case Demisting = 'demisting';
    case Defrost = 'defrost';

    public function getLabel(): string
    {
        return match ($this) {
            self::Automatically => __('Automatically'),
            self::Airco => __('Airco'),
            self::Heater => __('Heater'),
            self::Demisting => __('Demisting'),
            self::Defrost => __('Defrost'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Automatically => 'fa7-solid:temperature-high',
            self::Airco => 'mdi:air-conditioner',
            self::Heater => 'mdi:heat-wave',
            self::Demisting => 'mdi:wiper',
            self::Defrost => 'mdi:snowflake',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Automatically => 'warning',
            self::Airco => 'info',
            self::Heater => 'danger',
            self::Demisting => 'success',
            self::Defrost => 'info',
        };
    }
}
