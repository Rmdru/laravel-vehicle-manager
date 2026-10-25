<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;

enum FindingSource: string implements HasIcon, HasLabel
{
    case GarageAdvice = 'garage_advice';
    case Complaint = 'complaint';
    case MotDefect = 'mot_defect';
    case MaintenancePlan = 'maintenance_plan';

    public function getLabel(): string
    {
        return match ($this) {
            self::GarageAdvice => __('Advice of the garage'),
            self::Complaint => __('Complaint'),
            self::MotDefect => __('MOT defect'),
            self::MaintenancePlan => __('Maintenance plan'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::GarageAdvice => 'mdi:garage-alert',
            self::Complaint => 'mdi:car-info',
            self::MotDefect => 'mdi:clipboard-alert',
            self::MaintenancePlan => 'mdi:clipboard-list-outline',
        };
    }
}
