<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum FindingStatus: string implements HasIcon, HasLabel
{
    case Open = 'open';
    case Resolved = 'resolved';
    // Not needed after all, or it went away by itself.
    case Dropped = 'dropped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Resolved => __('Resolved'),
            self::Dropped => __('Dropped'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Open => 'mdi:alert-circle-outline',
            self::Resolved => 'ic:round-check',
            self::Dropped => 'ic:round-close',
        };
    }
}
