<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Vehicle;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * The image of a vehicle. It is stored upright as WebP in a few widths, so that
 * a browser can pick the one closest to the size it shows the image at: scaling
 * a photo straight from a phone down to a thumbnail makes it look grainy.
 */
class VehicleImage
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Pixels of the longest side of the stored image, at most. */
    private const MAX_SIZE = 1600;

    /** The widths the image is also stored at, when it is wider. */
    private const WIDTHS = [320, 640, 1280];

    /**
     * Store the image file at the given path as the image of the vehicle,
     * replacing the one it had. An image that cannot be read is kept as it is.
     */
    public static function store(Vehicle $vehicle, string $path, string $extension): void
    {
        $contents = (string) file_get_contents($path);
        $picture = self::read($contents, $path);

        self::delete($vehicle);

        $disk = Storage::disk('private');

        if ($picture === null) {
            $disk->put("vehicles/{$vehicle->id}.".strtolower($extension), $contents);

            return;
        }

        $disk->put("vehicles/{$vehicle->id}.webp", self::encode($picture));

        foreach (self::WIDTHS as $width) {
            if ($width < imagesx($picture)) {
                $disk->put(self::variantPath($vehicle, $width), self::encode(self::scale($picture, $width / imagesx($picture))));
            }
        }
    }

    public static function delete(Vehicle $vehicle): void
    {
        $disk = Storage::disk('private');

        // A vehicle has a single image, named after its id.
        foreach (self::EXTENSIONS as $extension) {
            $disk->delete("vehicles/{$vehicle->id}.{$extension}");
        }

        foreach (self::WIDTHS as $width) {
            $disk->delete(self::variantPath($vehicle, $width));
        }
    }

    /**
     * The path of the image at the given width, or of the largest one when it
     * is not stored at that width.
     */
    public static function path(Vehicle $vehicle, ?int $width = null): string
    {
        $variant = $width ? self::variantPath($vehicle, $width) : null;

        return $variant && Storage::disk('private')->exists($variant) ? $variant : $vehicle->image_path;
    }

    /**
     * The image in the widths it is available in, for the srcset of an <img>.
     */
    public static function srcset(Vehicle $vehicle): ?string
    {
        $disk = Storage::disk('private');
        $version = ['v' => $disk->lastModified($vehicle->image_path)];

        $candidates = collect(self::WIDTHS)
            ->filter(fn (int $width) => $disk->exists(self::variantPath($vehicle, $width)))
            ->map(fn (int $width) => route('vehicle.image', ['vehicle' => $vehicle->id, 'width' => $width, ...$version]).' '.$width.'w');

        if ($candidates->isEmpty()) {
            return null;
        }

        $largest = @getimagesize($disk->path($vehicle->image_path))[0] ?? null;

        if ($largest) {
            $candidates->push(route('vehicle.image', ['vehicle' => $vehicle->id, ...$version]).' '.$largest.'w');
        }

        return $candidates->implode(', ');
    }

    private static function variantPath(Vehicle $vehicle, int $width): string
    {
        return "vehicles/{$vehicle->id}-{$width}.webp";
    }

    /**
     * The image upright and no larger than the maximum size, or nothing when it cannot be read.
     */
    private static function read(string $contents, string $path): ?GdImage
    {
        if (! function_exists('imagewebp')) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return null;
        }

        // Scaled before it is rotated: a photo of a phone takes a lot of memory at full size.
        $picture = self::scale($source, min(1, self::MAX_SIZE / max(imagesx($source), imagesy($source))));

        // Phones store a photo as the sensor saw it and note how it was held.
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $degrees = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        if ($degrees !== 0) {
            $picture = imagerotate($picture, $degrees, 0) ?: $picture;
            imagesavealpha($picture, true);
        }

        return $picture;
    }

    private static function scale(GdImage $source, float $factor): GdImage
    {
        $target = imagecreatetruecolor(max(1, (int) round(imagesx($source) * $factor)), max(1, (int) round(imagesy($source) * $factor)));

        // Keep the transparency of a PNG or WebP.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), imagesx($source), imagesy($source));

        return $target;
    }

    private static function encode(GdImage $picture): string
    {
        ob_start();
        imagewebp($picture, null, 85);

        return (string) ob_get_clean();
    }
}
