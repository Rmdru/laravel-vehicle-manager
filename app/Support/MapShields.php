<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The road numbers on the trip map, as the road signs of their country show
 * them: the colors come from the road types of the countries, the shapes are
 * images in a sprite of the map style (public/map/shields).
 */
class MapShields
{
    /** European roads look the same everywhere. */
    public const EUROPEAN = ['background' => 'bg-green-600', 'color' => 'text-white', 'border' => null];

    /** A road number of a road type that is not configured. */
    public const PLAIN = ['background' => 'bg-white', 'color' => 'text-black', 'border' => 'border-gray-400'];

    /**
     * The color of a Tailwind class.
     */
    public static function hex(?string $class): ?string
    {
        return $class === null ? null : config("trip_planner.map.colors.{$class}");
    }

    /**
     * The name of the image of a shield in the sprite.
     *
     * @param  array{background: string, border: ?string}  $style
     */
    public static function image(array $style): string
    {
        return str_replace('bg-', '', $style['background']).'_'.str_replace('border-', '', $style['border'] ?? 'none');
    }

    /**
     * The road numbers by country (ISO 2 code): what they start with, their
     * image and the color of their text. The longest prefixes first, so that
     * AP is not taken for A.
     *
     * @return array<string, list<array{prefix: string, image: string, text: string}>>
     */
    public static function styles(): array
    {
        $styles = [];

        foreach (config('countries') as $country) {
            $types = collect($country['road_types'] ?? [])
                ->filter(fn (array $type) => filled($type['prefix'] ?? null))
                ->unique(fn (array $type) => $type['prefix'])
                ->sortByDesc(fn (array $type) => strlen($type['prefix']))
                ->map(fn (array $type) => [
                    'prefix' => $type['prefix'],
                    'image' => self::image(['background' => $type['background_color'], 'border' => $type['border']]),
                    'text' => self::hex($type['color']) ?? '#000000',
                ]);

            $styles[$country['iso_code']] = $types->values()->all();
        }

        return $styles;
    }

    /**
     * Every shield the sprite needs, by image name: its fill and its border.
     *
     * @return array<string, array{fill: string, border: ?string}>
     */
    public static function images(): array
    {
        $images = [];
        $add = function (array $style) use (&$images) {
            $images[self::image($style)] = [
                'fill' => self::hex($style['background']) ?? '#ffffff',
                'border' => self::hex($style['border'] ?? null),
            ];
        };

        $add(self::EUROPEAN);
        $add(self::PLAIN);

        foreach (config('countries') as $country) {
            foreach ($country['road_types'] ?? [] as $type) {
                $add(['background' => $type['background_color'], 'border' => $type['border']]);
            }
        }

        return $images;
    }
}
