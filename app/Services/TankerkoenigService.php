<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TankerkoenigService
{
    public function fetchGermanFuelPrices(): string
    {
        return $this->call('stats');
    }

    private function call(string $endpoint, array $params = []): string
    {
        // A failing request must not stop the prices of the other countries from being imported.
        try {
            $response = Http::timeout(5)
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36')
                ->retry(3, 100)
                ->get(config('tankerkoenig.base_url').$endpoint, $params);

            if ($response->ok()) {
                return $response->body();
            }
        } catch (\Exception $e) {
            Log::channel('fuel-prices')->warning('Tankerkoenig API call failed: '.$e->getMessage());
        }

        return '';
    }
}
