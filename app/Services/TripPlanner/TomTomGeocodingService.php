<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TomTomGeocodingService
{
    /**
     * Addresses and places in Europe that match what is typed so far.
     *
     * @return list<array{label: string, latitude: float, longitude: float}>
     */
    public function search(string $query, string $locale, ?float $nearLat = null, ?float $nearLon = null): array
    {
        try {
            $response = Http::timeout(5)->get(
                config('trip_planner.tomtom.base_url').'search/2/search/'.rawurlencode($query).'.json',
                array_filter([
                    'key' => config('trip_planner.tomtom.key'),
                    'typeahead' => 'true',
                    'limit' => 6,
                    'language' => config("trip_planner.tomtom.language.{$locale}", 'en-GB'),
                    'idxSet' => 'Geo,PAD,Addr,Str,POI',
                    // Results near home first.
                    'lat' => $nearLat,
                    'lon' => $nearLon,
                ], fn ($value) => $value !== null),
            );
        } catch (Throwable $exception) {
            Log::warning('TomTom search call failed.', ['message' => $exception->getMessage()]);

            return [];
        }

        if (! $response->ok()) {
            Log::warning('TomTom search HTTP response not OK', ['status' => $response->status()]);

            return [];
        }

        return collect($response->json('results') ?? [])
            ->map(fn (array $result) => [
                'label' => $this->label($result),
                'latitude' => (float) $result['position']['lat'],
                'longitude' => (float) $result['position']['lon'],
            ])
            ->unique('label')
            ->values()
            ->all();
    }

    /**
     * The address at a point, if it is known.
     */
    public function reverse(float $latitude, float $longitude, string $locale): ?string
    {
        try {
            $response = Http::timeout(5)->get(
                config('trip_planner.tomtom.base_url')."search/2/reverseGeocode/{$latitude},{$longitude}.json",
                [
                    'key' => config('trip_planner.tomtom.key'),
                    'language' => config("trip_planner.tomtom.language.{$locale}", 'en-GB'),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('TomTom reverse geocoding call failed.', ['message' => $exception->getMessage()]);

            return null;
        }

        $address = $response->ok() ? $response->json('addresses.0.address') : null;

        return $address ? $this->label(['address' => $address]) : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function label(array $result): string
    {
        $address = $result['address'] ?? [];
        $label = $address['freeformAddress'] ?? '';

        if (isset($result['poi']['name'])) {
            $label = $result['poi']['name'].', '.$label;
        }

        return isset($address['countryCode']) && ! str_ends_with($label, $address['country'] ?? '')
            ? $label.', '.$address['countryCode']
            : $label;
    }
}
