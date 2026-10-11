<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum TollPaymentMethod: string implements HasIcon, HasLabel
{
    case Cash = 'cash';
    case BankCard = 'bank_card';
    case Online = 'online';
    case TollBadge = 'toll_badge';
    case App = 'app';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::BankCard => __('Bank card'),
            self::Online => __('Online'),
            self::TollBadge => __('Toll badge'),
            self::App => __('App'),
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Cash => 'mdi:hand-coin-outline',
            self::BankCard => 'ic:baseline-credit-card',
            self::Online => 'ic:baseline-qr-code',
            self::TollBadge => 'mdi:car-connected',
            self::App => 'mdi:cellphone-wireless',
        };
    }
}
