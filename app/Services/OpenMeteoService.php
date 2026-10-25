<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenMeteoService
{
    /**
     * The coordinates of a city and the country it is in (a key of the
     * countries config, when known), or nothing when it is not found.
     *
     * @return array{lat: float, lon: float, country: string|null}|null
     */
    public function geocode(string $city): ?array
    {
        try {
            $response = Http::timeout(5)
                ->retry(3, 100)
                ->get(config('open_meteo.geocoding_url'), [
                    'name' => $city,
                    'count' => 1,
                    'format' => 'json',
                ]);

            $result = $response->json('results.0');

            if (isset($result['latitude'], $result['longitude'])) {
                $country = collect(config('countries'))->search(fn (array $country) => strcasecmp($country['iso_code'] ?? '', (string) ($result['country_code'] ?? '')) === 0);

                return ['lat' => (float) $result['latitude'], 'lon' => (float) $result['longitude'], 'country' => $country === false ? null : (string) $country];
            }
        } catch (\Throwable $exception) {
            Log::channel('openmeteo-api')->warning('OpenMeteo geocoding call failed.', [
                'message' => $exception->getMessage(),
                'city' => $city,
            ]);
        }

        return null;
    }

    /**
     * The weather forecast of the coming days, per day: the lowest and
     * highest temperature and the snowfall. Kept for a few hours: it is the
     * same for everyone nearby. Empty when the forecast is not available.
     *
     * @return list<array{date: string, min: float, max: float, snow_cm: float}>
     */
    public function forecast(float $latitude, float $longitude, int $days = 14): array
    {
        $latitude = round($latitude, 1);
        $longitude = round($longitude, 1);

        $forecast = Cache::get($key = "open-meteo:forecast:{$latitude}:{$longitude}:{$days}");

        if ($forecast !== null) {
            return $forecast;
        }

        try {
            $response = Http::timeout(5)
                ->retry(3, 100, throw: false)
                ->get(config('open_meteo.forecast_url'), [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'daily' => 'temperature_2m_min,temperature_2m_max,snowfall_sum',
                    'forecast_days' => $days,
                    'timezone' => 'auto',
                ]);

            $daily = $response->ok() ? (array) $response->json('daily') : [];
            $forecast = collect($daily['time'] ?? [])
                ->map(fn ($date, $index) => [
                    'date' => (string) $date,
                    'min' => (float) ($daily['temperature_2m_min'][$index] ?? 0),
                    'max' => (float) ($daily['temperature_2m_max'][$index] ?? 0),
                    'snow_cm' => (float) ($daily['snowfall_sum'][$index] ?? 0),
                ])
                ->values()
                ->all();

            if ($forecast === []) {
                Log::channel('openmeteo-api')->warning('OpenMeteo forecast returned no forecast.', [
                    'status' => $response->status(),
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);

                return [];
            }

            Cache::put($key, $forecast, now()->addHours(3));

            return $forecast;
        } catch (\Throwable $exception) {
            Log::channel('openmeteo-api')->warning('OpenMeteo forecast call failed.', [
                'message' => $exception->getMessage(),
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return [];
    }

    public function fetchHistoricalMinTempsInDateRange(array $ipLocation, string $startDate, string $endDate): array
    {
        try {
            $response = Http::timeout(5)
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36')
                ->retry(3, 100)
                ->get(config('open_meteo.base_url'), [
                    'latitude' => $ipLocation['lat'],
                    'longitude' => $ipLocation['lon'],
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'timezone' => 'auto',
                    'daily' => 'temperature_2m_min',
                ]);

            if ($response->ok()) {
                return $response->json();
            }

            Log::channel('openmeteo-api')->warning('OpenMeteo API returned a non-success response.', [
                'status' => $response->status(),
                'url' => $response->effectiveUri(),
                'latitude' => $ipLocation['lat'] ?? null,
                'longitude' => $ipLocation['lon'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        } catch (\Throwable $exception) {
            Log::channel('openmeteo-api')->warning('OpenMeteo API call failed.', [
                'message' => $exception->getMessage(),
                'url' => config('open_meteo.base_url'),
                'latitude' => $ipLocation['lat'] ?? null,
                'longitude' => $ipLocation['lon'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }

        return [];
    }
}
