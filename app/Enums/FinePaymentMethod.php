<?php

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum FinePaymentMethod: string implements HasIcon, HasLabel
{
    case Cash = 'cash';
    case BankCard = 'bank_card';
    case Online = 'online';
    case DirectDebit = 'direct_debit';
    case BankTransfer = 'bank_transfer';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::BankCard => __('Bank card'),
            self::Online => __('Online'),
            self::DirectDebit => __('Direct debit'),
            self::BankTransfer => __('Bank transfer'),
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Cash => 'mdi:hand-coin-outline',
            self::BankCard => 'ic:baseline-credit-card',
            self::Online => 'ic:baseline-qr-code',
            self::DirectDebit => 'fa7-solid:file-invoice-dollar',
            self::BankTransfer => 'mdi:bank-transfer',
        };
    }
}
