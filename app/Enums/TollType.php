<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum TollType: string implements HasIcon, HasLabel
{
    case Location = 'location';
    case Section = 'section';

    public function getLabel(): string
    {
        return match ($this) {
            self::Location => __('Location'),
            self::Section => __('Section'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Location => 'gmdi-location-on-r',
            self::Section => 'gmdi-route-r',
        };
    }
}
