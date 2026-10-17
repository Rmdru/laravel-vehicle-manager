<?php

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum FineProvider: string implements HasIcon, HasLabel
{
    case Police = 'police';
    case LocalPolice = 'local_police';
    case RoadOperator = 'road_operator';
    case Rdw = 'rdw';
    case OtherGovernment = 'other_government';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Police => __('Police'),
            self::LocalPolice => __('Local police'),
            self::RoadOperator => __('Road operator'),
            self::Rdw => __('National Road Transport Department'),
            self::OtherGovernment => __('Other government agency'),
            self::Other => __('Other'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Police => 'maki:police',
            self::LocalPolice => 'ic:baseline-local-police',
            self::RoadOperator => 'mdi:highway',
            self::Rdw => 'ic:round-emoji-transportation',
            self::OtherGovernment => 'ic:round-account-balance',
            default => '',
        };
    }
}
