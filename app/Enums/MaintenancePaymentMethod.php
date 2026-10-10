<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum MaintenancePaymentMethod: string implements HasIcon, HasLabel
{
    case Cash = 'cash';
    case BankCard = 'bank_card';
    case BankTransfer = 'bank_transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::BankCard => __('Bank card'),
            self::BankTransfer => __('Bank transfer'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Cash => 'mdi-hand-coin-outline',
            self::BankCard => 'gmdi-credit-card',
            self::BankTransfer => 'mdi-bank-transfer',
        };
    }
}
