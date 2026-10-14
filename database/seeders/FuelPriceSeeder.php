<?php

namespace Database\Seeders;

use App\Models\FuelPrice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Two years of made-up daily fuel prices, to have something to look at in the
 * fuel prices chart. Prices that are already there, such as imported ones, are kept.
 */
class FuelPriceSeeder extends Seeder
{
    private const DAYS = 730;

    /** The fuel types every country has prices of, with the price the history ends at. */
    private const PRICES = [
        'netherlands' => ['Unleaded 95 (E10)' => 2.43, 'Super Plus (E5)' => 2.73, 'Diesel' => 2.51, 'LPG' => 0.96],
        'belgium' => ['Unleaded 95 (E10)' => 2.07, 'Super Plus (E5)' => 2.26, 'Diesel' => 2.49, 'LPG' => 1.08],
        'luxembourg' => ['Unleaded 95 (E10)' => 1.88, 'Super Plus (E5)' => 2.08, 'Diesel' => 2.05, 'LPG' => 0.92],
        'germany' => ['Unleaded 95 (E10)' => 2.18, 'Unleaded 95 (E5)' => 2.23, 'Diesel' => 2.30],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('The fuel prices are not seeded in production.');

            return;
        }

        foreach (self::PRICES as $country => $fuelTypes) {
            foreach ($fuelTypes as $fuelType => $latest) {
                // The same prices every time it is run.
                mt_srand(crc32($country.$fuelType));

                $price = FuelPrice::query()
                    ->where(['country' => $country, 'fuel_type' => $fuelType])
                    ->latest('date')
                    ->value('price') ?? $latest;
                $price = $target = (float) $price;
                $rows = [];

                // Back in time from the most recent price, drifting around a slowly moving level.
                for ($day = 1; $day <= self::DAYS; $day++) {
                    $target += (mt_rand() / mt_getrandmax() - 0.52) * 0.012;
                    $price += ($target - $price) * 0.15 + (mt_rand() / mt_getrandmax() - 0.5) * 0.02;

                    $rows[] = [
                        'id' => (string) Str::uuid7(),
                        'date' => today()->subDays($day)->toDateString(),
                        'country' => $country,
                        'fuel_type' => $fuelType,
                        'price' => round(max($price, 0.5), 3),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    FuelPrice::query()->insertOrIgnore($chunk);
                }
            }
        }
    }
}
