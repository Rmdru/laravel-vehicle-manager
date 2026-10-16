<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Fine;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\Parking;
use App\Models\Product;
use App\Models\Reconditioning;
use App\Models\Refueling;
use App\Models\Service;
use App\Models\Tax;
use App\Models\Toll;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Gives every existing vehicle costs in each of the past twelve months, so the
 * monthly costs chart on the dashboard has something to show:
 * php artisan db:seed --class=MonthlyCostsSeeder
 *
 * Adds records on every run; it does not remove what is already there.
 */
class MonthlyCostsSeeder extends Seeder
{
    private const MONTHS = 12;

    public function run(): void
    {
        Vehicle::query()->each(fn (Vehicle $vehicle) => $this->seedVehicle($vehicle));

        // The dashboard caches its statistics for a while.
        Cache::flush();
    }

    private function seedVehicle(Vehicle $vehicle): void
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths(self::MONTHS - 1);

        $this->seedMonthlyInvoices($vehicle, $start);

        $fuelTypes = $vehicle->fuel_types ?: ['Unleaded 95 (E10)'];
        $lastRefueling = Refueling::where('vehicle_id', $vehicle->id)->max('date');
        $mileage = (int) max(
            (int) Refueling::where('vehicle_id', $vehicle->id)->max('mileage_end'),
            (int) $vehicle->mileage_latest,
            (int) $vehicle->mileage_start,
        );

        for ($index = 0; $index < self::MONTHS; $index++) {
            $month = $start->addMonths($index);
            $days = min($month->daysInMonth, $month->isSameMonth($today) ? $today->day : 31);
            $day = fn () => $month->day(fake()->numberBetween(1, $days));

            // Refuelings continue where the mileage left off, so only after the last one.
            foreach ([(int) ceil($days / 3), (int) ceil($days * 2 / 3)] as $dayOfMonth) {
                $date = $month->day($dayOfMonth);

                if ($lastRefueling && $date->lte($lastRefueling)) {
                    continue;
                }

                $distance = fake()->numberBetween(450, 800);
                $amount = fake()->randomFloat(2, 32, 55);
                $unitPrice = fake()->randomFloat(3, 1.75, 2.15);

                Refueling::factory()->for($vehicle)->create([
                    'date' => $date->toDateString(),
                    'country' => $vehicle->country_registration ?: 'netherlands',
                    'fuel_type' => fake()->randomElement($fuelTypes),
                    'amount' => $amount,
                    'unit_price' => $unitPrice,
                    'total_price' => round($amount * $unitPrice, 2),
                    'mileage_begin' => $mileage,
                    'mileage_end' => $mileage + $distance,
                    'fuel_consumption' => round($amount / $distance * 100, 2),
                    'costs_per_kilometer' => round($amount * $unitPrice / $distance, 2),
                    'avg_speed' => fake()->numberBetween(45, 95),
                ]);

                $mileage += $distance;
            }

            Parking::factory()->for($vehicle)->count(fake()->numberBetween(0, 3))->create(fn () => [
                'start_time' => ($time = $day()->setTime(fake()->numberBetween(8, 18), 0))->toDateTimeString(),
                'end_time' => $time->addHours(fake()->numberBetween(1, 4))->toDateTimeString(),
                'price' => fake()->randomFloat(2, 2, 18),
            ]);

            Toll::factory()->for($vehicle)->count(fake()->numberBetween(0, 2))->create(fn () => [
                'start_date' => $day()->toDateString(),
                'price' => fake()->randomFloat(2, 3, 35),
            ]);

            if ($index % 2 === 0) {
                Reconditioning::factory()->for($vehicle)->create([
                    'date' => $day()->toDateString(),
                    'price' => fake()->randomFloat(2, 8, 45),
                ]);
            }

            if ($index % 3 === 1) {
                Product::factory()->for($vehicle)->create([
                    'date' => $day()->toDateString(),
                    'price' => fake()->randomFloat(2, 10, 80),
                ]);
            }

            if ($index % 6 === 2) {
                Maintenance::factory()->for($vehicle)->create([
                    'date' => $day()->toDateString(),
                    'mileage' => $mileage,
                    'total_price' => fake()->randomFloat(2, 180, 650),
                ]);
            }

            if ($index === 4) {
                Fine::factory()->for($vehicle)->create([
                    'date' => $day()->toDateString(),
                    'price' => fake()->randomFloat(2, 40, 160),
                ]);
            }

            if ($index === 8) {
                Service::factory()->for($vehicle)->create([
                    'date' => $day()->toDateString(),
                    'price' => fake()->randomFloat(2, 20, 120),
                ]);
            }
        }

        if ($mileage > (int) $vehicle->mileage_latest) {
            $vehicle->update(['mileage_latest' => $mileage]);
        }
    }

    /**
     * Insurance and road tax are invoiced monthly; only add them when the
     * vehicle has none yet that covers the present.
     */
    private function seedMonthlyInvoices(Vehicle $vehicle, CarbonImmutable $start): void
    {
        $period = [
            'start_date' => $start->toDateString(),
            'end_date' => $start->addYears(2)->toDateString(),
            'invoice_day' => 1,
        ];

        foreach ([Insurance::class => [45, 110], Tax::class => [25, 90]] as $model => [$minimum, $maximum]) {
            $covered = $model::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereDate('end_date', '>=', today())
                ->exists();

            if (! $covered) {
                $model::factory()->for($vehicle)->create([...$period, 'price' => fake()->randomFloat(2, $minimum, $maximum)]);
            }
        }
    }
}
