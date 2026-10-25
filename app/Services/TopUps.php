<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Vehicle;
use App\Support\Odometer;
use App\Support\StatusNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the fluids topped up tell about the vehicle: an engine that burns or
 * loses oil, a cooling system that leaks, or brakes that wear or leak. The
 * products of a fluid with the mileage they were topped up at.
 */
class TopUps
{
    /** Kilometers driven at least, for the oil consumption to say anything. */
    private const OIL_KM_AT_LEAST = 1000;

    /**
     * Liters of oil per 1000 km from which it is worth watching, and from
     * which to have it looked at. Most engines use well under 0.1; makers
     * often allow up to 0.5, some more.
     */
    private const OIL_WATCH = 0.3;

    private const OIL_CHECK = 0.5;

    /** Months over which coolant topped up more than once points to a leak. */
    private const COOLANT_MONTHS = 6;

    /** Months over which brake fluid topped up is worth a check of the brakes. */
    private const BRAKE_FLUID_MONTHS = 12;

    /**
     * The liters of engine oil per 1000 km: since the last oil change when
     * the maintenance plan knows it, or else between the top-ups of the past
     * year. Null when too little was driven to tell.
     *
     * @return array{per_1000_km: float, liters: float, km: int, since: string}|null
     */
    public function oilConsumption(Vehicle $vehicle): ?array
    {
        $topUps = $this->of($vehicle, ProductCategory::EngineOil);
        $change = collect(app(MaintenancePlanService::class)->plan($vehicle))->firstWhere('key', 'engine_oil')['done'] ?? null;
        [$now] = Odometer::of($vehicle)->at(CarbonImmutable::today());

        if ($change !== null && $change['km'] !== null && $now !== null) {
            $since = $topUps->filter(fn (Product $product) => $product->mileage > $change['km']);
            $km = (int) round($now - $change['km']);
            $from = $change['date'];
        } else {
            // Without the oil change, from the first top-up: what it filled up was used before it.
            $since = $topUps->filter(fn (Product $product) => $product->date->gte(today()->subYear()))->sortBy('mileage')->values();
            $km = $since->count() >= 2 ? (int) $since->last()->mileage - (int) $since->first()->mileage : 0;
            $from = $since->first()?->date?->toDateString();
            $since = $since->slice(1);
        }

        if ($since->isEmpty() || $km < self::OIL_KM_AT_LEAST) {
            return null;
        }

        $liters = (float) $since->sum('quantity');

        return [
            'per_1000_km' => round($liters / $km * 1000, 2),
            'liters' => round($liters, 2),
            'km' => $km,
            'since' => (string) $from,
        ];
    }

    /**
     * The notifications of what the top-ups tell.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        $types = StatusNotification::types();
        $notification = fn (string $key, string $type, string $text, string $icon) => [
            ...$types[$type],
            'key' => 'top_ups:'.$key,
            'type' => $type,
            'text' => $text,
            'icon' => $icon,
            'typeIcon' => $types[$type]['icon'],
            'hasModal' => false,
            'data' => [],
            'url' => route('products.index'),
            'urlLabel' => __('Products & accessory'),
        ];
        $notifications = [];

        $oil = $this->oilConsumption($vehicle);

        if ($oil !== null && $oil['per_1000_km'] >= self::OIL_WATCH) {
            $amount = number_format($oil['per_1000_km'], 1, ',', '.');

            $notifications[] = $oil['per_1000_km'] >= self::OIL_CHECK
                ? $notification('engine_oil', 'warning', __('The engine uses :amount l of oil per 1000 km: have it checked for a leak, the turbo, the valve stem seals or the piston rings.', ['amount' => $amount]), 'mdi:oil-level')
                : $notification('engine_oil', 'info', __('The engine uses :amount l of oil per 1000 km: more than most. Keep an eye on the level.', ['amount' => $amount]), 'mdi:oil-level');
        }

        $coolant = $this->of($vehicle, ProductCategory::Coolant)->filter(fn (Product $product) => $product->date->gte(today()->subMonths(self::COOLANT_MONTHS)));

        if ($coolant->count() >= 2) {
            $notifications[] = $notification('coolant', 'warning', __('Coolant topped up :count times in half a year: have the cooling system checked for a leak (hoses, radiator, water pump) or the head gasket.', ['count' => $coolant->count()]), 'mdi:car-coolant-level');
        }

        $brakeFluid = $this->of($vehicle, ProductCategory::BrakeFluid)->filter(fn (Product $product) => $product->date->gte(today()->subMonths(self::BRAKE_FLUID_MONTHS)));

        if ($brakeFluid->isNotEmpty()) {
            $notifications[] = $notification('brake_fluid', 'warning', __('Brake fluid was topped up: its level drops when the brake pads wear or the brakes leak. Have the brakes checked.'), 'mdi:car-brake-fluid-level');
        }

        return $notifications;
    }

    /**
     * What the top-ups tell, for the facts of the tips.
     *
     * @return array<string, mixed>|null
     */
    public function facts(Vehicle $vehicle): ?array
    {
        $count = fn (ProductCategory $category, int $months) => $this->of($vehicle, $category)->filter(fn (Product $product) => $product->date->gte(today()->subMonths($months)))->count();

        $facts = array_filter([
            'engine_oil_liters_per_1000_km' => $this->oilConsumption($vehicle)['per_1000_km'] ?? null,
            'coolant_top_ups_past_year' => $count(ProductCategory::Coolant, 12) ?: null,
            'brake_fluid_top_ups_past_year' => $count(ProductCategory::BrakeFluid, 12) ?: null,
        ], fn ($value) => $value !== null);

        return $facts ?: null;
    }

    /**
     * The top-ups of a fluid, the earliest first.
     *
     * @return Collection<int, Product>
     */
    private function of(Vehicle $vehicle, ProductCategory $category): Collection
    {
        return Product::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', $category->value)
            ->get()
            ->filter(fn (Product $product) => $product->isTopUp())
            ->sortBy('mileage')
            ->values();
    }
}
