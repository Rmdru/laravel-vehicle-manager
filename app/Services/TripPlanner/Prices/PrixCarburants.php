<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use Illuminate\Support\Facades\Http;

/**
 * France: the prices every gas station reports to the government.
 */
class PrixCarburants implements StationPrices
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
