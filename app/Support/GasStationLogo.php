<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class GasStationLogo
{
    /** Iconify sets to look for the logo of a brand in, in order of preference. */
    private const ICON_SETS = ['thesvg-color', 'simple-icons'];

    /** Folder on the local disk the logos fetched from Iconify are kept in. */
    private const FOLDER = 'gas-station-logos';

    /** Days before a brand Iconify had no logo for is looked up again. */
    private const RETRY_MISSING_AFTER_DAYS = 30;

    /** @var array<string, string|null> The logos as data URIs, by brand. */
    private static array $icons = [];

    /**
     * The URL of the logo of a gas station. Its brand is the first word of its name.
     * Our own image of the brand when there is one, else its logo on Iconify, else
     * the default image.
     */
    public static function url(?string $gasStation): string
    {
        $logos = config('refuelings.gas_station_logos');
        $brand = (string) str((string) $gasStation)->lower()->explode(' ')->first();

        if (isset($logos[$brand])) {
            return asset('images/gas-stations/'.$logos[$brand]);
        }

        return self::icon($brand) ?? asset('images/gas-stations/'.$logos['default']);
    }

    private static function icon(string $brand): ?string
    {
        if (! preg_match('/^[a-z0-9-]+$/', $brand)) {
            return null;
        }

        if (! array_key_exists($brand, self::$icons)) {
            $svg = self::svg($brand);

            self::$icons[$brand] = $svg === null ? null : 'data:image/svg+xml;base64,'.base64_encode(self::sized($svg));
        }

        return self::$icons[$brand];
    }

    /**
     * The logo in resources/icons, like the logos of car brands, or else fetched
     * from Iconify once and kept, as is the fact that Iconify has none.
     */
    private static function svg(string $brand): ?string
    {
        foreach (self::ICON_SETS as $set) {
            $path = resource_path('icons/'.$set.'/'.$brand.'.svg');

            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        $disk = Storage::disk('local');
        $logo = self::FOLDER.'/'.$brand.'.svg';
        $missing = self::FOLDER.'/'.$brand.'.missing';

        if ($disk->exists($logo)) {
            return $disk->get($logo);
        }

        if ($disk->exists($missing) && $disk->lastModified($missing) > now()->subDays(self::RETRY_MISSING_AFTER_DAYS)->getTimestamp()) {
            return null;
        }

        foreach (self::ICON_SETS as $prefix) {
            // A short wait: the logos are looked up while a page is being rendered.
            $svg = Iconify::svg(Iconify::set($prefix, [$brand], timeout: 3, tries: 1) ?? [], $brand);

            if ($svg !== null) {
                $disk->put($logo, $svg);
                $disk->delete($missing);

                return $svg;
            }
        }

        $disk->put($missing, '');

        return null;
    }

    /**
     * Gives the logo a size, large enough to be scaled down: an image without one is
     * not drawn at the size of the box it is in.
     */
    private static function sized(string $svg): string
    {
        if (! preg_match('/viewBox="[\d.-]+ [\d.-]+ ([\d.]+) ([\d.]+)"/', $svg, $viewBox)) {
            return $svg;
        }

        return preg_replace('/<svg\b/', '<svg width="'.((float) $viewBox[1] * 10).'" height="'.((float) $viewBox[2] * 10).'"', $svg, 1);
    }
}
