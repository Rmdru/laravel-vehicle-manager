<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Reconditioning;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Gives every existing vehicle a year of washing, for the washing costs on the
 * dashboard: washed by the owner once a month with detailing products, at the
 * carwash in the cold months against the road salt, now and then the interior
 * and once in a while a hand wash by a company.
 * php artisan db:seed --class=WashingSeeder
 *
 * Replaces the washes and detailing products of the past year on every run.
 */
class WashingSeeder extends Seeder
{
    private const MONTHS = 12;

    /** The detailing products bought, with what they cost about. */
    private const PRODUCTS = [
        ['Detailrs Car Shampoo 500 ml', 14.95],
        ['Detailrs Wheel Cleaner 500 ml', 16.95],
        ['Detailrs Drying Towel', 24.95],
        ['Detailrs Quick Detailer 500 ml', 17.95],
        ['Detailrs Interior Cleaner 500 ml', 13.95],
        ['Detailrs Ceramic Spray Sealant 500 ml', 29.95],
        ['Detailrs Wash Mitt', 9.95],
        ['Detailrs Glass Cleaner 500 ml', 11.95],
    ];

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

        $vehicle->reconditionings()->where('date', '>=', $start)
            ->whereIn('type', ['carwash', 'exterior_cleaning', 'interior_cleaning'])
            ->forceDelete();
        $vehicle->products()->where('date', '>=', $start)
            ->where('category', ProductCategory::Cleaning->value)
            ->delete();

        $products = collect(self::PRODUCTS)->shuffle()->values();

        for ($index = 0; $index < self::MONTHS; $index++) {
            $month = $start->addMonths($index);
            $days = $month->isSameMonth($today) ? $today->day : $month->daysInMonth;
            $day = fn () => $month->day(fake()->numberBetween(1, $days))->toDateString();

            // Below 4 °C the road is salted: the carwash, with the underbody, and in between
            // a wash of your own on a milder day. Otherwise once a month yourself.
            if (in_array($month->month, [12, 1, 2], true)) {
                $this->wash($vehicle, $day(), 'carwash', 'company', fake()->randomElement([12.5, 14.0, 15.5, 17.0]), fake()->randomElement(['Washtec Utrecht', 'Wasstraat De Meern', 'Carwash Nieuwegein']));

                if (fake()->boolean(60)) {
                    $this->wash($vehicle, $day(), 'carwash', 'company', fake()->randomElement([12.5, 14.0, 15.5]), 'Washtec Utrecht');
                }
            } elseif (in_array($month->month, [11, 3], true) && fake()->boolean(50)) {
                $this->wash($vehicle, $day(), 'carwash', 'company', 14.0, 'Washtec Utrecht');
            } else {
                $this->wash($vehicle, $day(), 'exterior_cleaning', 'myself', 0, 'Thuis');
            }

            if ($index % 3 === 1) {
                $this->wash($vehicle, $day(), 'interior_cleaning', 'myself', 0, 'Thuis');
            }

            // Bought now and then, and the most in spring.
            if (in_array($month->month, [3, 4, 6, 9], true) || fake()->boolean(15)) {
                foreach ($products->splice(0, fake()->numberBetween(1, 3)) as [$name, $price]) {
                    Product::factory()->for($vehicle)->create([
                        'name' => $name,
                        'category' => ProductCategory::Cleaning,
                        'quantity' => null,
                        'mileage' => null,
                        'price' => $price,
                        'date' => $day(),
                    ]);
                }

                if ($products->isEmpty()) {
                    $products = collect(self::PRODUCTS)->shuffle()->values();
                }
            }
        }

        // Once in a while a hand wash by a company, before a trip or a sale.
        if (fake()->boolean(50)) {
            $this->wash($vehicle, $start->addMonths(fake()->numberBetween(4, 8))->day(15)->toDateString(), 'exterior_cleaning', 'company', 39.95, 'Handwas Centrum');
        }
    }

    private function wash(Vehicle $vehicle, string $date, string $type, string $executor, float $price, string $location): void
    {
        Reconditioning::factory()->for($vehicle)->create([
            'date' => $date,
            'type' => $type,
            'executor' => $executor,
            'price' => $price,
            'location' => $location,
            'description' => null,
        ]);
    }
}
