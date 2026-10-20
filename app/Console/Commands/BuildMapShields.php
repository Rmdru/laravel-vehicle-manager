<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MapShields;
use GdImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BuildMapShields extends Command
{
    protected $signature = 'map:shields';

    protected $description = 'Build the sprite with the road number shields of the trip map, from the road types of the countries';

    /** The size of a shield at a pixel ratio of 1. */
    private const WIDTH = 20;

    private const HEIGHT = 18;

    /** Drawn this many times larger, and then scaled down, for smooth edges. */
    private const SUPERSAMPLE = 4;

    public function handle(): int
    {
        $images = MapShields::images();
        File::ensureDirectoryExists(public_path('map'));

        foreach ([1 => 'shields', 2 => 'shields@2x'] as $ratio => $name) {
            $width = self::WIDTH * $ratio;
            $height = self::HEIGHT * $ratio;
            $sprite = imagecreatetruecolor($width * count($images), $height);
            imagesavealpha($sprite, true);
            imagefill($sprite, 0, 0, imagecolorallocatealpha($sprite, 0, 0, 0, 127));
            $index = [];

            foreach (array_values($images) as $position => $image) {
                imagecopy($sprite, $this->shield($image['fill'], $image['border'], $ratio), $position * $width, 0, 0, 0, $width, $height);
            }

            foreach (array_keys($images) as $position => $key) {
                // The shield stretches around the road number, but not at its corners.
                $index[$key] = [
                    'x' => $position * $width,
                    'y' => 0,
                    'width' => $width,
                    'height' => $height,
                    'pixelRatio' => $ratio,
                    'stretchX' => [[4 * $ratio, 16 * $ratio]],
                    'stretchY' => [[4 * $ratio, 14 * $ratio]],
                    'content' => [3 * $ratio, 2 * $ratio, 17 * $ratio, 16 * $ratio],
                ];
            }

            imagepng($sprite, public_path("map/{$name}.png"));
            File::put(public_path("map/{$name}.json"), json_encode($index, JSON_PRETTY_PRINT));
        }

        $this->info('Built '.count($images).' shields.');

        return self::SUCCESS;
    }

    /**
     * A rounded rectangle in a color, with a border in another one.
     */
    private function shield(string $fill, ?string $border, int $ratio): GdImage
    {
        $scale = $ratio * self::SUPERSAMPLE;
        $large = imagecreatetruecolor(self::WIDTH * $scale, self::HEIGHT * $scale);
        imagesavealpha($large, true);
        imagealphablending($large, false);
        imagefill($large, 0, 0, imagecolorallocatealpha($large, 0, 0, 0, 127));
        imagealphablending($large, true);

        $radius = 3 * $scale;
        $this->roundedRectangle($large, 0, 0, self::WIDTH * $scale, self::HEIGHT * $scale, $radius, $this->color($large, $border ?? $fill));

        if ($border) {
            $inset = (int) round(1.25 * $scale);
            $this->roundedRectangle($large, $inset, $inset, self::WIDTH * $scale - $inset, self::HEIGHT * $scale - $inset, $radius - $inset, $this->color($large, $fill));
        }

        $small = imagecreatetruecolor(self::WIDTH * $ratio, self::HEIGHT * $ratio);
        imagesavealpha($small, true);
        imagealphablending($small, false);
        imagecopyresampled($small, $large, 0, 0, 0, 0, self::WIDTH * $ratio, self::HEIGHT * $ratio, self::WIDTH * $scale, self::HEIGHT * $scale);

        return $small;
    }

    private function roundedRectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius - 1, $y2 - 1, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2 - 1, $y2 - $radius - 1, $color);

        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius - 1, $y1 + $radius], [$x1 + $radius, $y2 - $radius - 1], [$x2 - $radius - 1, $y2 - $radius - 1]] as [$x, $y]) {
            imagefilledellipse($image, $x, $y, 2 * $radius, 2 * $radius, $color);
        }
    }

    private function color(GdImage $image, string $hex): int
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');

        return imagecolorallocate($image, $red, $green, $blue);
    }
}
