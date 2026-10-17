<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Support\VehicleImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class OptimizeVehicleImages extends Command
{
    protected $signature = 'vehicles:optimize-images';

    protected $description = 'Store the images of vehicles that were uploaded before images were resized in all their widths';

    public function handle(): void
    {
        $disk = Storage::disk('private');

        Vehicle::query()->withTrashed()->chunkById(100, function ($vehicles) use ($disk) {
            foreach ($vehicles as $vehicle) {
                if (! $vehicle->image_exists) {
                    continue;
                }

                $path = $vehicle->image_path;
                $isSmall = str_ends_with($path, '.webp') && ((($size = @getimagesize($disk->path($path))) === false ? 0 : $size[0]) <= 320);

                // Images that already have their widths, or need none, would only lose quality by being stored again.
                if ($isSmall || VehicleImage::srcset($vehicle) !== null) {
                    continue;
                }

                VehicleImage::store($vehicle, $disk->path($path), pathinfo($path, PATHINFO_EXTENSION));

                $this->info("Optimized the image of vehicle {$vehicle->id}.");
            }
        });
    }
}
