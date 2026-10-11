<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum VehicleStatus: string implements HasIcon, HasLabel
{
    case Drivable = 'drivable';
    case Suspended = 'suspended';
    case Wok = 'wok';
    case Apk = 'apk';
    case Seized = 'seized';
    case Stolen = 'stolen';
    case Sold = 'sold';
    case NotRollable = 'not_rollable';
    case Destroyed = 'destroyed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Drivable => __('Drivable'),
            self::Suspended => __('Suspended'),
            self::Wok => __('WOK status'),
            self::Apk => __('Invalid MOT'),
            self::Seized => __('Seized'),
            self::Stolen => __('Stolen'),
            self::Sold => __('Sold'),
            self::NotRollable => __('Not rollable'),
            self::Destroyed => __('Destroyed'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Drivable => 'mdi:check',
            self::Suspended => 'mdi:garage',
            self::Wok => 'mdi:shield-off',
            self::Apk => 'mdi:shield-alert',
            self::Seized => 'maki:police',
            self::Stolen => 'mdi:lock-open-alert',
            self::Sold => 'ic:baseline-local-offer',
            self::NotRollable => 'fa7-solid:car-burst',
            self::Destroyed => 'mdi:fire',
        };
    }

    /**
     * The badge color that tells whether the vehicle can be driven.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Drivable => 'success',
            self::Suspended, self::Sold => 'gray',
            default => 'danger',
        };
    }
}
