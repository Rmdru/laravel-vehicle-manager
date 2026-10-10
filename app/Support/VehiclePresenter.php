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

        return [
            'id' => $vehicle->id,
            'name' => $vehicle->full_name,
            'brand' => $brand,
            'brandLogo' => self::brandLogo($brand),
            'licensePlate' => Roads::licensePlate($vehicle->country_registration, (string) $vehicle->license_plate),
            'mileage' => $vehicle->mileage_latest ?? $vehicle->mileage_start,
            'powertrain' => $vehicle->powertrain,
            'imageUrl' => $vehicle->image_url ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(Vehicle $vehicle): array
    {
        $status = VehicleStatus::tryFrom((string) $vehicle->status) ?? VehicleStatus::Drivable;

        return [
            ...self::summary($vehicle),
            'mileageStart' => $vehicle->mileage_start,
            'age' => $vehicle->construction_date?->age,
            'constructionDate' => $vehicle->construction_date?->format('d-m-Y'),
            'status' => $status->getLabel(),
            'statusIcon' => $status->getIcon(),
            'showUrl' => route('vehicles.show', $vehicle),
            'editUrl' => route('vehicles.edit', $vehicle),
            'selectUrl' => route('vehicles.select', $vehicle),
            'destroyUrl' => route('vehicles.destroy', $vehicle),
        ];
    }

    /**
     * The name of the brand's logo in resources/icons/brands (Simple Icons),
     * or null when that pack has no logo for the brand.
     */
    public static function brandLogo(string $brand): ?string
    {
        $name = (string) str($brand)->replace([' ', '-'], '')->lower()->ascii();

        return is_file(resource_path('icons/brands/'.$name.'.svg')) ? $name : null;
    }
}
