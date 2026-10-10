<?php

declare(strict_types=1);

namespace App\Support;

class Roads
{
    private const DEFAULT_CLASSES = 'border border-gray-300 bg-white text-black';

    /**
     * Road sign badges for one or more road numbers, styled per country and road type.
     *
     * @param  string|list<string>|null  $road
     * @return list<array{label: string, classes: string}>
     */
    public static function badges(?string $country, ?string $roadType, string|array|null $road): array
    {
        $roads = array_values(array_filter((array) $road, fn ($value) => filled($value)));
        $config = config('countries')[$country]['road_types'][$roadType] ?? null;

        return array_map(function (string $road) use ($config) {
            if (! $config) {
                return ['label' => $road, 'classes' => self::DEFAULT_CLASSES];
            }

            // The configured prefix replaces whatever letters the user typed in front of the number.
            $number = substr($road, strcspn($road, '0123456789'));

            return [
                'label' => ($config['prefix'] ?? '').$number,
                'classes' => trim(implode(' ', [
                    ! empty($config['border']) ? 'border '.$config['border'] : '',
                    $config['background_color'],
                    $config['color'],
                ])),
            ];
        }, $roads);
    }

    /**
     * @return array{label: string, prefix: ?string, plateClasses: string, euBar: bool}
     */
    public static function licensePlate(?string $country, string $licensePlate): array
    {
        $config = config('countries')[$country]['license_plate'] ?? [];

        return [
            'label' => $licensePlate,
            'prefix' => $config['prefix'] ?? null,
            'plateClasses' => trim(implode(' ', [
                ! empty($config['border']) ? 'border '.$config['border'] : '',
                $config['background_color'] ?? 'bg-white',
                $config['color'] ?? 'text-black',
            ])),
            'euBar' => (bool) ($config['eu_bar'] ?? false),
        ];
    }
}
