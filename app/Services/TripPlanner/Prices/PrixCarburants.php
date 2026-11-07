<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use Illuminate\Support\Facades\Http;

/**
 * France: the prices every gas station reports to the government.
 */
class PrixCarburants implements BulkStationPrices, StationPrices
{
    /**
     * The fields of a fuel, in order of preference: along the motorway most
     * gas stations sell 95 as E10 only, which then gives the price.
     */
    private const FUELS = [
        'e10' => ['e10', 'sp95'],
        'e5' => ['sp95', 'e10'],
        'super_plus' => ['sp98'],
        'diesel' => ['gazole'],
        'lpg' => ['gplc'],
    ];

    /** The fuel types of the app, by field. */
    private const FUEL_TYPES = [
        'e10' => 'Unleaded 95 (E10)',
        'sp95' => 'Unleaded 95 (E5)',
        'sp98' => 'Super Plus (E5)',
        'gazole' => 'Diesel',
        'gplc' => 'LPG',
    ];

    /** The pages of a hundred stations read at most around a point. */
    private const PAGES = 4;

    public function country(): string
    {
        return 'FR';
    }

    /** The fuels of the app, by field, for the import of all stations. */
    private const ALL = [
        'e10' => 'e10',
        'sp95' => 'e5',
        'sp98' => 'super_plus',
        'gazole' => 'diesel',
        'gplc' => 'lpg',
    ];

    /**
     * All gas stations at once, by the export of the dataset rather than its
     * pages of a hundred.
     */
    public function all(): iterable
    {
        $fields = array_keys(self::ALL);
        $select = implode(',', array_merge(['id', 'geom', 'ville'], ...array_map(fn (string $field) => ["{$field}_prix", "{$field}_maj"], $fields)));
        $url = str_replace('/records', '/exports/json', (string) config('trip_planner.prices.france_url'));

        foreach (Http::timeout(120)->get($url, ['select' => $select])->throw()->json() ?? [] as $record) {
            $lat = $record['geom']['lat'] ?? null;
            $lon = $record['geom']['lon'] ?? null;

            if (! is_numeric($lat) || ! is_numeric($lon)) {
                continue;
            }

            $prices = [];

            foreach (self::ALL as $field => $fuel) {
                if (is_numeric($record["{$field}_prix"] ?? null) && (float) $record["{$field}_prix"] > 0) {
                    $prices[] = [
                        'fuel' => $fuel,
                        'fuel_type' => self::FUEL_TYPES[$field],
                        'price' => (float) $record["{$field}_prix"],
                        'service' => null,
                        'updated_at' => $record["{$field}_maj"] ?? null,
                    ];
                }
            }

            if ($prices) {
                yield [
                    'id' => (string) ($record['id'] ?? "{$lat},{$lon}"),
                    'name' => isset($record['ville']) ? (string) $record['ville'] : null,
                    'latitude' => (float) $lat,
                    'longitude' => (float) $lon,
                    'prices' => $prices,
                ];
            }
        }
    }

    public function near(float $latitude, float $longitude, string $fuel): array
    {
        $fields = self::FUELS[$fuel] ?? [];

        if (! $fields) {
            return [];
        }

        $select = implode(',', array_merge(['geom'], ...array_map(fn (string $field) => ["{$field}_prix", "{$field}_maj"], $fields)));
        $stations = [];

        for ($page = 0; $page < self::PAGES; $page++) {
            $records = Http::timeout(15)->get(config('trip_planner.prices.france_url'), [
                'select' => $select,
                'where' => sprintf("within_distance(geom, geom'POINT(%F %F)', 25km)", $longitude, $latitude),
                'limit' => 100,
                'offset' => $page * 100,
            ])->throw()->json('results') ?? [];

            foreach ($records as $record) {
                $field = collect($fields)->first(fn (string $field) => is_numeric($record["{$field}_prix"] ?? null));

                if ($field !== null) {
                    $stations[] = [
                        'latitude' => (float) $record['geom']['lat'],
                        'longitude' => (float) $record['geom']['lon'],
                        'price' => (float) $record["{$field}_prix"],
                        'updated_at' => $record["{$field}_maj"] ?? null,
                        'fuel_type' => self::FUEL_TYPES[$field],
                    ];
                }
            }

            if (count($records) < 100) {
                break;
            }
        }

        return $stations;
    }
}
