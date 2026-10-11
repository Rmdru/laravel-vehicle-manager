<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum RefuelingTires: string implements HasIcon, HasLabel
{
    case AllSeason = 'all_season';
    case Summer = 'summer';
    case Winter = 'winter';

    public function getLabel(): string
    {
        return match ($this) {
            self::AllSeason => __('All season tires'),
            self::Summer => __('Summer tires'),
            self::Winter => __('Winter tires'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::AllSeason => 'material-symbols:sunny-snowing',
            self::Summer => 'ic:outline-wb-sunny',
            self::Winter => 'forkawesome-snowflake-o',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AllSeason => 'danger',
            self::Summer => 'warning',
            self::Winter => 'info',
        };
    }
}
