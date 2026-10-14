<?php

declare(strict_types=1);

namespace App\Pipelines\ImportFuelPrices;

use App\Services\AnwbFuelPriceService;
use Illuminate\Support\Facades\Log;

/**
 * The prices the other sources lack, from the averages the ANWB publishes.
 */
class Anwb
{
    public function handle($data, $next): array
    {
        $crawledData = (new AnwbFuelPriceService)->crawlAnwbFuelPrices();

        if (empty($crawledData)) {
            Log::channel('fuel-prices')->warning('Failed to fetch ANWB fuel prices: No data returned');

            return $next($data);
        }

        foreach (config('anwb_fuel_prices.countries') as $country => $config) {
            foreach ($config['fuel_types'] as $fuelType => $code) {
                $price = $crawledData[$config['iso3_code']][$code] ?? null;

                if ($price === null) {
                    Log::channel('fuel-prices')->warning("Missing ANWB fuel price: {$country} - {$code}");

                    continue;
                }

                // The page has no date of its own (what it shows is just today): like the
                // maximum prices of Belgium and Luxembourg, it is the price of the day it is read.
                $data[] = [
                    'date' => now(),
                    'country' => $country,
                    'fuel_type' => $fuelType,
                    'price' => $price,
                ];
            }
        }

        return $next($data);
    }
}
