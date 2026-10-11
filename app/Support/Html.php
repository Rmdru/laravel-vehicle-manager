<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small HTML fragments for table cells, which the table builder renders as-is.
 */
class Html
{
    private const BADGE_COLORS = [
        'gray' => 'bg-muted text-muted-foreground',
        'success' => 'bg-success-muted text-success',
        'danger' => 'bg-danger-muted text-danger',
        'warning' => 'bg-warning-muted text-warning',
        'info' => 'bg-info-muted text-info',
    ];

    /** @var array<string, string|null> */
    private static array $icons = [];

    /**
     * An icon from resources/icons as inline SVG, or nothing when it does not exist.
     * The name is the Iconify one, e.g. "mdi:car-wrench"; add icons with `php artisan icons:add`.
     */
    public static function icon(?string $name, string $class = 'size-4 shrink-0'): string
    {
        if (blank($name) || ! preg_match('/^[a-z0-9-]+:[a-z0-9-]+$/', $name)) {
            return '';
        }

        if (! array_key_exists($name, self::$icons)) {
            $path = resource_path('icons/'.str_replace(':', '/', $name).'.svg');
            $svg = is_file($path) ? (string) file_get_contents($path) : null;

            // Sizing and styling are left to the classes of whoever renders it.
            self::$icons[$name] = $svg === null ? null : preg_replace_callback(
                '/<svg\b[^>]*>/',
                fn (array $tag) => preg_replace('/\s(?:width|height|class|id)="[^"]*"/', '', $tag[0]),
                $svg,
                1,
            );
        }

        $svg = self::$icons[$name];

        return $svg === null ? '' : preg_replace('/<svg\b/', '<svg class="'.e($class).'" aria-hidden="true"', $svg, 1);
    }

    /**
     * Escaped text preceded by an icon, as the table cells show it.
     */
    public static function iconText(string $icon, ?string $text): string
    {
        if (blank($text)) {
            return '';
        }

        return '<span class="inline-flex items-center gap-1.5">'
            .self::icon($icon, 'size-5 shrink-0 text-muted-foreground')
            .'<span>'.e($text).'</span>'
            .'</span>';
    }

    /**
     * A large, standalone icon that leads a table row.
     */
    public static function rowIcon(?string $name, string $fallback): string
    {
        $class = 'size-9 text-muted-foreground';

        return self::icon($name, $class) ?: self::icon($fallback, $class);
    }

    public static function badge(string $label, string $color = 'gray', ?string $icon = null): string
    {
        $classes = self::BADGE_COLORS[$color] ?? self::BADGE_COLORS['gray'];

        return '<span class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap '.$classes.'">'
            .self::icon($icon, 'size-3.5 shrink-0')
            .e($label)
            .'</span>';
    }

    public static function flag(?string $country, bool $showName = false): string
    {
        $config = config('countries')[$country] ?? null;

        if (! $config) {
            return '';
        }

        $name = e($config['name']);

        return '<span class="inline-flex items-center gap-2" title="'.$name.'">'
            .self::icon('circle-flags:'.strtolower($config['iso_code']), 'size-6 shrink-0 rounded-full')
            .($showName ? $name : '<span class="sr-only">'.$name.'</span>')
            .'</span>';
    }

    /**
     * @param  string|list<string>|null  $road
     */
    public static function roadBadges(?string $country, ?string $roadType, string|array|null $road): string
    {
        return collect(Roads::badges($country, $roadType, $road))
            ->map(fn (array $badge) => '<span class="inline-block rounded px-1 font-bold '.e($badge['classes']).'">'.e($badge['label']).'</span>')
            ->implode(' ');
    }

    public static function logo(string $url): string
    {
        return '<span class="flex h-12 w-16 items-center justify-center rounded-lg border bg-white p-1.5">'
            .'<img src="'.e($url).'" alt="" class="max-h-full max-w-full" loading="lazy" />'
            .'</span>';
    }
}
