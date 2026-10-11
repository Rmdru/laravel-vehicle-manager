<?php

declare(strict_types=1);

namespace App\Pipelines\ImportFuelPrices;

use App\Services\LuxembourgFuelPriceService;
use Illuminate\Support\Facades\Log;

class Luxembourg
{
    public function handle($data, $next): array
    {
        $crawledData = (new LuxembourgFuelPriceService)->crawlLuxembourgFuelPrices();

        if (empty($crawledData)) {
            Log::channel('fuel-prices')->warning('Failed to fetch Luxembourg fuel prices: No data returned');

            return $next($data);
        }

        $config = config('luxembourg_fuel_prices');

        foreach ($crawledData as $fuelType) {
            $fuelTypeKey = array_search($fuelType['fuel_type'], $config['fuel_types']);

            if ($fuelTypeKey === false) {
                Log::channel('fuel-prices')->warning("Unknown Luxembourg fuel type: {$fuelType['fuel_type']}");

                continue;
            }

            $data[] = [
                'date' => now(),
                'country' => 'luxembourg',
                'fuel_type' => $fuelTypeKey,
                'price' => $fuelType['price'],
            ];
        }

        return $next($data);
    }
}
