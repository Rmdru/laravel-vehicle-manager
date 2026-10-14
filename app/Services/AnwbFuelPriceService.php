<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnwbFuelPriceService
{
    /**
     * The average fuel prices the ANWB publishes for European countries, by the
     * ISO 3 code of the country and the ANWB code of the fuel type.
     *
     * @return array<string, array<string, float>>
     */
    public function crawlAnwbFuelPrices(): array
    {
        try {
            $response = Http::timeout(10)
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36')
                ->retry(3, 100)
                ->get(config('anwb_fuel_prices.crawl_url'));

            if (! $response->ok()) {
                Log::channel('fuel-prices')->warning('ANWB fuel prices HTTP response not OK', [
                    'status' => $response->status(),
                    'url' => config('anwb_fuel_prices.crawl_url'),
                ]);

                return [];
            }

            // The prices are not in the HTML itself, but in the data the page is rendered from.
            if (! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $response->body(), $match)) {
                Log::channel('fuel-prices')->warning('ANWB fuel prices page has no page data');

                return [];
            }

            $prices = [];

            foreach ($this->countries(json_decode($match[1], true)) as $country) {
                foreach ($country['fuelPrices'] as $fuelPrice) {
                    if (isset($fuelPrice['fuelTypeCode'], $fuelPrice['averagePrice'])) {
                        $prices[$country['iso3CountryCode']][$fuelPrice['fuelTypeCode']] = (float) $fuelPrice['averagePrice'];
                    }
                }
            }

            return $prices;
        } catch (\Throwable $e) {
            Log::channel('fuel-prices')->error('ANWB fuel prices crawling failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [];
        }
    }

    /**
     * The countries with their prices, wherever they are in the page data: its
     * structure is not meant to be read by others and its keys are generated.
     *
     * @return list<array{iso3CountryCode: string, fuelPrices: list<array<string, mixed>>}>
     */
    private function countries(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['iso3CountryCode']) && is_array($data['fuelPrices'] ?? null)) {
            return [$data];
        }

        return array_merge(...array_map(fn ($value) => $this->countries($value), array_values($data)));
    }
}
