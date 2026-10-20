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
     * The badge of a road number as a route planner gives it, styled after the
     * road type of the country with the same prefix. European roads are green
     * everywhere.
     *
     * @param  string|null  $isoCode  The ISO 2 code of the country.
     * @return array{label: string, classes: string}
     */
    public static function badge(?string $isoCode, string $road): array
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z].*$/', '', $road));

        if ($prefix === 'E') {
            return ['label' => $road, 'classes' => 'bg-green-600 text-white'];
        }

        $country = collect(config('countries'))
            ->first(fn (array $country) => $isoCode !== null && strtoupper($isoCode) === $country['iso_code']);

        foreach ($country['road_types'] ?? [] as $config) {
            if ($prefix !== '' && strtoupper((string) ($config['prefix'] ?? '')) === $prefix) {
                return ['label' => $road, 'classes' => trim(implode(' ', [
                    ! empty($config['border']) ? 'border '.$config['border'] : '',
                    $config['background_color'],
                    $config['color'],
                ]))];
            }
        }

        return ['label' => $road, 'classes' => self::DEFAULT_CLASSES];
    }

    /**
     * The badge of a road number in one of several countries, when it is not
     * known which: the first country with a road type of its prefix.
     *
     * @param  list<string>  $isoCodes  The ISO 2 codes of the countries.
     * @return array{label: string, classes: string}
     */
    public static function badgeIn(array $isoCodes, string $road): array
    {
        foreach ($isoCodes as $isoCode) {
            $badge = self::badge($isoCode, $road);

            if ($badge['classes'] !== self::DEFAULT_CLASSES) {
                return $badge;
            }
        }

        return self::badge($isoCodes[0] ?? null, $road);
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
