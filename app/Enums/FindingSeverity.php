<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

/**
 * How urgent a finding is: urgent ones and those to have done soon are
 * notifications under "Needs attention", the ones for information are not.
 */
enum FindingSeverity: string implements HasIcon, HasLabel
{
    case Urgent = 'urgent';
    case Soon = 'soon';
    case Info = 'info';

    public function getLabel(): string
    {
        return match ($this) {
            self::Urgent => __('Urgent'),
            self::Soon => __('Have it done soon'),
            self::Info => __('For information'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Urgent => 'ic:round-warning',
            self::Soon => 'ic:round-error',
            self::Info => 'ic:round-info',
        };
    }
}
