<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum AccidentType: string implements HasIcon, HasLabel
{
    case OneSided = 'one_sided';
    case MultiSide = 'multi_sided';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::OneSided => __('One-sided'),
            self::MultiSide => __('Multi-sided'),
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::OneSided => 'mdi-car',
            self::MultiSide => 'mdi-car-multiple',
        };
    }
}
