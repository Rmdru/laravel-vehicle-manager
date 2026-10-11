<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum TollPaymentCircumstances: string implements HasIcon, HasLabel
{
    case TollGate = 'toll_gate';
    case Camera = 'camera';

    public function getLabel(): string
    {
        return match ($this) {
            self::TollGate => __('Toll gate'),
            self::Camera => __('Camera'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::TollGate => 'mdi:boom-gate',
            self::Camera => 'mdi:cctv',
        };
    }
}
