<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum RefuelingPaymentMethod: string implements HasIcon, HasLabel
{
    case Cash = 'cash';
    case BankCard = 'bank_card';
    case LoyalityProgram = 'loyality_program';
    case FuelCard = 'fuel_card';
    case App = 'app';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::BankCard => __('Bank card'),
            self::LoyalityProgram => __('Loyality program'),
            self::FuelCard => __('Fuel card'),
            self::App => __('App'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cash => 'mdi:hand-coin-outline',
            self::BankCard => 'ic:baseline-credit-card',
            self::LoyalityProgram => 'mdi:gift',
            self::FuelCard => 'ic:round-local-gas-station',
            self::App => 'mdi:cellphone-wireless',
        };
    }
}
