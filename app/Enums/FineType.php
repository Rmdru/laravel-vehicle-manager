<?php

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum FineType: string implements HasIcon, HasLabel
{
    case Camera = 'camera';
    case Officer = 'officer';
    case TrafficStop = 'traffic_stop';
    case Automated = 'automated';
    case TrafficControl = 'traffic_control';
    case BorderControl = 'border_control';

    public function getLabel(): string
    {
        return match ($this) {
            self::Camera => __('Camera'),
            self::Officer => __('Officer'),
            self::TrafficStop => __('Traffic stop'),
            self::Automated => __('Automated'),
            self::TrafficControl => __('Traffic control'),
            self::BorderControl => __('Border control'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Camera => 'mdi:cctv',
            self::Officer => 'maki:police',
            self::TrafficStop => 'mdi:car-emergency',
            self::Automated => 'ic:sharp-timer',
            self::TrafficControl => 'mdi:map-marker-radius',
            self::BorderControl => 'fa7-solid:road-barrier',
        };
    }
}
