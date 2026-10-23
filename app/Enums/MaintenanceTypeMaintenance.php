<?php

declare(strict_types=1);

namespace App\Enums;

use App\Contracts\HasLabel;

enum MaintenanceTypeMaintenance: string implements HasLabel
{
    case TirePressureChecked = 'tire_pressure_checked';
    case LiquidsChecked = 'liquids_checked';
    case Maintenance = 'maintenance';
    case SmallMaintenance = 'small_maintenance';
    case BigMaintenance = 'big_maintenance';
    case OilService = 'oil_service';
    case InspectionService = 'inspection_service';
    case Longlife = 'longlife';

    public function getLabel(): string
    {
        return match ($this) {
            self::TirePressureChecked => __('Tire pressure checked'),
            self::LiquidsChecked => __('Liquids checked'),
            self::Maintenance => __('Maintenance'),
            self::SmallMaintenance => __('Small maintenance'),
            self::BigMaintenance => __('Big maintenance'),
            self::OilService => __('Oil service'),
            self::InspectionService => __('Inspection service'),
            self::Longlife => __('Longlife service'),
        };
    }
}
