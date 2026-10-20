<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use Illuminate\Support\Facades\Http;

/**
 * The prices at gas stations across Europe the ANWB shows on its website.
 * Not open data: only used when it is turned on in the configuration.
 */
class Anwb implements StationPrices
{
    /** The fuel types of the ANWB, by fuel. */
    private const FUELS = ['e10' => ['EURO95'], 'e5' => ['EURO95'], 'super_plus' => ['EURO98'], 'diesel' => ['DIESEL'], 'lpg' => ['AUTOGAS', 'LPG']];

    public function __construct(private readonly string $country) {}

    public function country(): string
    {
        return $this->country;
    }

    public function near(float $latitude, float $longitude, string $fuel): array
    {
        $types = self::FUELS[$fuel] ?? [];

        if (! $types) {
            return [];
        }

        $stations = Http::timeout(15)->withUserAgent('Mozilla/5.0')->get(config('trip_planner.prices.anwb.url'), [
            'type-filter' => 'FUEL_STATION',
            // About the 25 km around the point the other sources look in.
            'bounding-box-filter' => implode(',', [round($latitude - 0.23, 4), round($longitude - 0.35, 4), round($latitude + 0.23, 4), round($longitude + 0.35, 4)]),
        ])->throw()->json('value') ?? [];

        $found = [];

        foreach ($stations as $station) {
            $price = collect($station['prices'] ?? [])->first(fn (array $price) => in_array($price['fuelType'] ?? null, $types, true)
                && ($price['currency'] ?? 'EUR') === 'EUR' && is_numeric($price['value'] ?? null));

            if ($price && isset($station['coordinates']['latitude'], $station['coordinates']['longitude'])) {
                $name = (string) ($price['fuelName'] ?? '');
                $found[] = [
                    'latitude' => (float) $station['coordinates']['latitude'],
                    'longitude' => (float) $station['coordinates']['longitude'],
                    'price' => (float) $price['value'],
                    'updated_at' => null,
                    'fuel_type' => match (true) {
                        str_contains($name, 'E10') => 'Unleaded 95 (E10)',
                        $price['fuelType'] === 'EURO95' => 'Unleaded 95 (E5)',
                        $price['fuelType'] === 'EURO98' => 'Super Plus (E5)',
                        $price['fuelType'] === 'DIESEL' => 'Diesel',
                        default => 'LPG',
                    },
                ];
            }
        }

        return $found;
    }
}
