<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;

/**
 * Display data for a vehicle. Vehicles are never serialized directly, because
 * their appended status attributes are expensive to calculate.
 */
class VehiclePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Vehicle $vehicle): array
    {
        $brand = config('vehicles.brands')[$vehicle->brand] ?? (string) $vehicle->brand;
        $status = VehicleStatus::tryFrom((string) $vehicle->status) ?? VehicleStatus::Drivable;

        return [
            'id' => $vehicle->id,
            'name' => $vehicle->full_name,
            'brand' => $brand,
            'brandLogo' => self::brandLogo($brand),
            'licensePlate' => Roads::licensePlate($vehicle->country_registration, (string) $vehicle->license_plate),
            'mileage' => $vehicle->mileage_latest ?? $vehicle->mileage_start,
            'powertrain' => $vehicle->powertrain,
            'imageUrl' => $vehicle->image_url ?: null,
            'age' => $vehicle->construction_date?->age,
            'constructionDate' => $vehicle->construction_date?->format('d-m-Y'),
            'status' => $status->getLabel(),
            'statusIcon' => $status->getIcon(),
            'statusColor' => $status->getColor(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(Vehicle $vehicle): array
    {
        return [
            ...self::summary($vehicle),
            'mileageStart' => $vehicle->mileage_start,
            'showUrl' => route('vehicles.show', $vehicle),
            'editUrl' => route('vehicles.edit', $vehicle),
            'selectUrl' => route('vehicles.select', $vehicle),
            'destroyUrl' => route('vehicles.destroy', $vehicle),
        ];
    }

    /**
     * The Iconify name of the brand's logo in resources/icons: in the brand's
     * colors when that set has it, else monochrome, or null without a logo.
     */
    public static function brandLogo(string $brand): ?string
    {
        $name = (string) str($brand)->lower()->ascii();

        foreach (['thesvg-color', 'simple-icons'] as $set) {
            foreach ([str_replace([' ', '-'], '', $name), str_replace(' ', '-', $name)] as $icon) {
                if (is_file(resource_path('icons/'.$set.'/'.$icon.'.svg'))) {
                    return $set.':'.$icon;
                }
            }
        }

        return null;
    }
}
