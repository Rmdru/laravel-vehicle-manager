<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\Vehicle;

/**
 * How far a vehicle gets on a tank: its tank capacity, and its consumption as
 * it shows from its refuelings.
 */
class VehicleRange
{
    /** The refuelings the consumption is based on. */
    private const REFUELINGS = 10;

    /** A motorway takes more fuel than the average the refuelings tell. */
    private const MOTORWAY_FACTOR = 1.1;

    /**
     * @param  float  $consumption  In l/100 km.
     * @param  bool  $fromRefuelings  Whether the consumption comes from the refuelings, or is a default.
     * @param  string|null  $fuelType  The fuel type the vehicle is refueled with, as the app names it.
     * @param  bool  $electric  Whether it is charged rather than refueled: the tank is a battery in kWh, the consumption in kWh/100 km.
     */
    public function __construct(
        public float $tank,
        public float $consumption,
        public bool $fromRefuelings,
        public ?string $fuelType = null,
        public bool $electric = false,
    ) {}

    /**
     * The part of the battery or the tank that is left when a stop is made to
     * charge or refuel.
     */
    public function reserve(): float
    {
        return (float) config($this->electric ? 'trip_planner.ev.reserve' : 'trip_planner.stops.fuel_reserve');
    }

    /**
     * The part of the battery or the tank that is full after a stop: charging
     * goes slow beyond 80%.
     */
    public function fillTo(): float
    {
        return $this->electric ? (float) config('trip_planner.ev.charge_to') : 1.0;
    }

    /**
     * The range of a vehicle that runs on a liquid fuel and has a known tank
     * capacity; nothing for any other.
     */
    public static function of(Vehicle $vehicle): ?self
    {
        $powertrain = (string) $vehicle->powertrain;

        if ((float) $vehicle->tank_capacity <= 0) {
            return null;
        }

        if ($powertrain === 'electricity') {
            return self::electric($vehicle);
        }

        if (! preg_match('/^(gasoline|diesel)/', $powertrain) || str_contains($powertrain, 'cng')) {
            return null;
        }

        // The first fuel type of the vehicle that gas stations sell.
        $fuelType = collect((array) $vehicle->fuel_types)->first(fn ($type) => FuelPrices::fuel($type) !== null);
        $refuelings = $vehicle->refuelings()->newest()->where('fuel_consumption', '>', 0)->limit(self::REFUELINGS)->get();
        $motorway = $refuelings->filter(fn ($refueling) => in_array('motorway', (array) $refueling->routes, true));

        if ($motorway->count() >= 3) {
            return new self((float) $vehicle->tank_capacity, round((float) $motorway->avg('fuel_consumption'), 2), true, $fuelType);
        }

        if ($refuelings->isNotEmpty()) {
            return new self((float) $vehicle->tank_capacity, round((float) $refuelings->avg('fuel_consumption') * self::MOTORWAY_FACTOR, 2), true, $fuelType);
        }

        $default = config('trip_planner.default_consumption.'.(str_starts_with($powertrain, 'diesel') ? 'diesel' : 'gasoline'));

        return new self((float) $vehicle->tank_capacity, (float) $default, false, $fuelType);
    }

    /**
     * An electric vehicle: its battery, and its consumption from its charging sessions.
     */
    private static function electric(Vehicle $vehicle): self
    {
        $consumption = $vehicle->refuelings()->newest()->where('fuel_consumption', '>', 0)->limit(self::REFUELINGS)->avg('fuel_consumption');

        return $consumption
            ? new self((float) $vehicle->tank_capacity, round((float) $consumption * self::MOTORWAY_FACTOR, 2), true, null, true)
            : new self((float) $vehicle->tank_capacity, (float) config('trip_planner.ev.default_consumption'), false, null, true);
    }

    /**
     * The minutes it takes to charge an amount of energy, in kWh, at a power
     * in kW: the vehicle charges slower than the charger can, and slows down
     * as the battery fills.
     */
    public function chargeMinutes(float $energy, ?float $power): int
    {
        $power = min($power ?: (float) config('trip_planner.ev.default_power'), (float) config('trip_planner.ev.max_power'));

        return (int) ceil($energy / ($power * (float) config('trip_planner.ev.power_factor')) * 60);
    }

    /**
     * The distance in meters the vehicle drives on an amount of fuel.
     */
    public function metersOn(float $liters): float
    {
        return $liters / $this->consumption * 100 * 1000;
    }

    /**
     * The fuel in liters the vehicle uses over a distance in meters.
     */
    public function litersFor(float $meters): float
    {
        return $meters / 1000 / 100 * $this->consumption;
    }
}
