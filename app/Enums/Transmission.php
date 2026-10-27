<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasLabel;

/**
 * The transmission of a vehicle: manual or automatic. Electric vehicles are
 * always automatic.
 */
enum Transmission: string implements HasLabel
{
    case Manual = 'manual';
    case Automatic = 'automatic';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => __('Manual'),
            self::Automatic => __('Automatic'),
        };
    }

    /** The name the AI model gets. */
    public function englishName(): string
    {
        return $this->value;
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Manual => 'mdi:car-shift-pattern',
            self::Automatic => 'mdi:car-shift-pattern',
        };
    }
}
