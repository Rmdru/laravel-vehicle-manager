<?php

declare(strict_types=1);

namespace App\Support;

class StatusNotification
{
    public static function configuration(): array
    {
        return [
            'insurance' => [
                'statusKey' => 'insurance_status',
                'thresholds' => ['critical' => 0, 'warning' => 31, 'info' => 62],
                'thresholdType' => 'days',
                'messages' => [
                    'critical' => __('No active insurance found! Your are currently not allowed to drive with the vehicle!'),
                    'warning' => __('Insurance expires within 1 month!'),
                    'info' => __('Insurance expires within 2 months!'),
                ],
                'icon' => 'mdi-shield-car',
            ],
            'tax' => [
                'statusKey' => 'tax_status',
                'thresholds' => ['info' => 31],
                'thresholdType' => 'days',
                'messages' => [
                    'info' => __('New tax period within 1 month!'),
                ],
                'icon' => 'mdi-highway',
            ],
            'apk' => [
                'statusKey' => 'apk_status',
                'thresholds' => ['critical' => 1, 'warning' => 31, 'info' => 62],
                'thresholdType' => 'days',
                'messages' => [
                    'critical' => __('MOT expired! Your are currently not allowed to drive with the vehicle!'),
                    'warning' => __('MOT expires within 1 month!'),
                    'info' => __('MOT expires within 2 months!'),
                ],
                'icon' => 'gmdi-security',
            ],
            'maintenance' => [
                'statusKey' => 'maintenance_status',
                'thresholds' => ['critical' => 31, 'warning' => 62],
                'thresholdType' => 'days',
                'messages' => [
                    'critical' => __('Maintenance required now'),
                    'warning' => __('Maintenance required soon'),
                ],
                'icon' => 'mdi-car-wrench',
            ],
            'airco_check' => [
                'statusKey' => 'airco_check_status',
                'thresholds' => ['critical' => 31, 'warning' => 62],
                'thresholdType' => 'days',
                'messages' => [
                    'critical' => __('Airco check required!'),
                    'warning' => __('Airco check required soon!'),
                ],
                'icon' => 'mdi-air-conditioner',
            ],
            'refueling' => [
                'statusKey' => 'fuel_status',
                'thresholds' => ['critical' => 10, 'warning' => 30],
                'thresholdType' => 'days',
                'messages' => [
                    'critical' => __('Fuel is too old!'),
                    'warning' => __('Fuel is getting old!'),
                ],
                'icon' => 'gmdi-local-gas-station-r',
            ],
            'periodic_e5' => [
                'statusKey' => 'periodic_e5',
                'thresholds' => ['info' => 3],
                'thresholdType' => 'recordCount',
                'messages' => [
                    'info' => __('Next time fill up with E5 fuel!'),
                ],
                'icon' => 'gmdi-local-gas-station-r',
            ],
            'washing' => [
                'statusKey' => 'washing_status',
                'thresholds' => ['warning' => 5, 'info' => 10],
                'thresholdType' => 'days',
                'messages' => [
                    'warning' => __('Washing required!'),
                    'info' => __('Washing required soon!'),
                ],
                'icon' => 'mdi-car-wash',
            ],
            'tire_pressure_check' => [
                'statusKey' => 'tire_pressure_check_status',
                'thresholds' => ['warning' => 10, 'info' => 20],
                'thresholdType' => 'days',
                'messages' => [
                    'warning' => __('Check tire pressure!'),
                    'info' => __('Check tire pressure soon!'),
                ],
                'icon' => 'mdi-car-tire-alert',
            ],
            'liquids_check' => [
                'statusKey' => 'liquids_check_status',
                'thresholds' => ['warning' => 5, 'info' => 10],
                'thresholdType' => 'days',
                'messages' => [
                    'warning' => __('Check liquids!'),
                    'info' => __('Check liquids soon!'),
                ],
                'icon' => 'mdi-oil',
            ],
        ];
    }

    public static function types(): array
    {
        return [
            'critical' => [
                'priority' => 0,
                'textColor' => 'text-red-500',
                'borderColor' => 'border-red-500',
                'filamentColor' => 'danger',
                'icon' => 'gmdi-warning-r',
                'badgeText' => __('Attention required'),
            ],
            'warning' => [
                'priority' => 1,
                'textColor' => 'text-orange-400',
                'borderColor' => 'border-orange-500',
                'filamentColor' => 'warning',
                'icon' => 'gmdi-error-r',
                'badgeText' => __('Attention recommended'),
            ],
            'info' => [
                'priority' => 2,
                'textColor' => 'text-blue-400',
                'borderColor' => 'border-blue-400',
                'filamentColor' => 'info',
                'icon' => 'gmdi-info-r',
                'badgeText' => __('Notification'),
            ],
            'success' => [
                'priority' => 3,
                'textColor' => 'text-green-500',
                'borderColor' => 'border-green-500',
                'filamentColor' => 'success',
                'icon' => 'gmdi-check-r',
                'badgeText' => __('OK'),
            ],
        ];
    }
}