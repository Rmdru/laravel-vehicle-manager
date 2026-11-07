<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Jobs\FetchPoiTiles;
use App\Models\Poi;
use App\Models\Refueling;
use App\Models\StationPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The gas stations and charging stations on the station map, within the
 * bounds of the map: from the places the trip planner keeps per tile, so that
 * nothing has to be fetched while the map is moved. Zoomed out as clusters
 * with a count, zoomed in one by one with their prices.
 */
class StationMap
{
    /** The kinds of places on the map, by the name of the filter. */
    public const KINDS = ['fuel' => Poi::GAS_STATION, 'fast' => Poi::CHARGER, 'slow' => Poi::SLOW_CHARGER];

    /** From this zoom the stations are shown one by one; below it as clusters. */
    public const STATIONS_FROM = 11;

    /** From this zoom the prices of a source that is asked per area are asked: as soon as the stations show one by one. */
    public const LIVE_PRICES_FROM = 11;

    /** From this zoom the tiles of the area are fetched when they are not there yet. */
    public const FETCH_FROM = 9;

    /** The stations shown at once, at most: zoomed in further the rest shows. */
    public const MAX_STATIONS = 1500;

    /** The most tiles fetched for an area at once. */
    private const MAX_FETCH_TILES = 48;

    /** Meters a price of a source may lie from a place of OpenStreetMap to be its price. */
    private const SAME_STATION = 250;

    /** The fuels of the app, by the fuels the price sources know them by. */
    public const FUELS = [
        'Unleaded 95 (E10)' => 'e10',
        'Unleaded 95 (E5)' => 'e5',
        'Super Plus (E5)' => 'super_plus',
        'Diesel' => 'diesel',
        'LPG' => 'lpg',
        'CNG' => 'cng',
    ];

    public function __construct(private readonly PoiRepository $pois, private readonly FuelPrices $prices) {}

    /**
     * The clusters within bounds: per cell of the grid of the zoom the count
     * of each kind. Only by kind: the filters on fuel types and connectors
     * take the tags of every place, which is for the stations one by one.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds  South, west, north, east.
     * @param  list<string>  $kinds  Of KINDS.
     * @return list<array{latitude: float, longitude: float, count: int, counts: array<string, int>}>
     */
    public function clusters(array $bounds, int $zoom, array $kinds): array
    {
        $cell = 360 / (2 ** $zoom) / 5;
        [$south, $west, $north, $east] = $bounds;

        $rows = DB::table('pois')
            ->selectRaw('kind, ROUND(latitude / ? - 0.5) as y, ROUND(longitude / ? - 0.5) as x, COUNT(*) as n, AVG(latitude) as lat, AVG(longitude) as lon', [$cell, $cell])
            ->whereIn('kind', array_values(array_intersect_key(self::KINDS, array_flip($kinds))))
            ->whereBetween('latitude', [$south, $north])
            ->whereBetween('longitude', [$west, $east])
            ->groupBy('kind', 'y', 'x')
            ->get();

        $names = array_flip(self::KINDS);

        return $rows->groupBy(fn ($row) => $row->y.':'.$row->x)
            ->map(function (Collection $cell) use ($names) {
                $count = (int) $cell->sum('n');

                return [
                    // Where the places of the cell are, on average.
                    'latitude' => round($cell->sum(fn ($row) => $row->lat * $row->n) / $count, 5),
                    'longitude' => round($cell->sum(fn ($row) => $row->lon * $row->n) / $count, 5),
                    'count' => $count,
                    'counts' => $cell->mapWithKeys(fn ($row) => [$names[$row->kind] => (int) $row->n])->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The stations within bounds, filtered, with what they cost where that is
     * known: of a source that publishes all prices at once (France, Spain, the
     * United Kingdom, Italy), or else whether a source can be asked for this
     * area (Germany, Austria), and the last price the owner paid there.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds
     * @param  array{kinds: list<string>, fuels: list<string>, connectors: list<string>, priced: bool}  $filters
     * @return array{stations: list<array<string, mixed>>, truncated: bool}
     */
    public function stations(array $bounds, array $filters, ?User $user): array
    {
        [$south, $west, $north, $east] = $bounds;
        $places = Poi::query()
            ->whereIn('kind', array_values(array_intersect_key(self::KINDS, array_flip($filters['kinds']))))
            ->whereBetween('latitude', [$south, $north])
            ->whereBetween('longitude', [$west, $east])
            ->limit(self::MAX_STATIONS + 1)
            ->get();

        $truncated = $places->count() > self::MAX_STATIONS;
        $places = $places->take(self::MAX_STATIONS)->filter(fn (Poi $poi) => $this->matches($poi, $filters));
        $stored = $this->storedPrices($bounds);
        $own = $this->ownPrices($user);
        $names = array_flip(self::KINDS);

        $stations = $places->map(function (Poi $poi) use ($stored, $own, $names, $filters) {
            $country = $this->countryOf($poi);
            $isFuel = $poi->kind === Poi::GAS_STATION;
            $prices = $isFuel ? $this->nearestPrices($stored, $poi) : [];

            return [
                'id' => $poi->id,
                'kind' => $names[$poi->kind],
                'name' => $poi->name,
                'brand' => $poi->brand,
                'latitude' => (float) $poi->latitude,
                'longitude' => (float) $poi->longitude,
                'country' => $country,
                'details' => $poi->details(),
                'services' => $poi->services(),
                'connectors' => $isFuel ? [] : $poi->connectors(),
                'power' => $isFuel ? null : $poi->maxPower(),
                'prices' => $this->onlyFuels($prices, $filters['fuels']),
                // Asked for this area apart, see livePrices().
                'live' => $isFuel && $prices === [] && $country !== null && $this->prices->hasSource($country),
                'own' => $isFuel ? $this->ownPriceAt($own, $poi) : null,
            ];
        });

        if ($filters['priced']) {
            $stations = $stations->filter(fn (array $station) => $station['kind'] !== 'fuel' || $station['prices'] !== [] || $station['live']);
        }

        return ['stations' => $stations->values()->all(), 'truncated' => $truncated];
    }

    /**
     * The prices of the gas stations within bounds from a source that is
     * asked per area (Germany, Austria), by place: once zoomed in, and kept
     * per area for a while (see FuelPrices).
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds
     * @param  list<string>  $fuelTypes  The fuel types of the app; none for the usual ones.
     * @param  string|null  $primary  The fuel type beside every gas station on the map: always asked.
     * @return array<int, list<array<string, mixed>>>
     */
    public function livePrices(array $bounds, array $fuelTypes, ?string $primary = null): array
    {
        [$south, $west, $north, $east] = $bounds;
        $types = [...($fuelTypes ?: ['Unleaded 95 (E10)', 'Super Plus (E5)', 'Diesel']), ...($primary !== null ? [$primary] : [])];
        $fuels = array_values(array_unique(array_intersect_key(self::FUELS, array_flip($types))));
        // Not the fuels no source asked per area knows.
        $fuels = array_values(array_diff($fuels, ['cng']));
        $result = [];

        $places = Poi::query()
            ->where('kind', Poi::GAS_STATION)
            ->whereBetween('latitude', [$south, $north])
            ->whereBetween('longitude', [$west, $east])
            ->limit(self::MAX_STATIONS)
            ->get();

        foreach ($places as $poi) {
            $country = $this->countryOf($poi);

            if ($country === null || ! $this->prices->hasSource($country)) {
                continue;
            }

            foreach ($fuels as $fuel) {
                $price = $this->prices->atStation($country, (float) $poi->latitude, (float) $poi->longitude, $fuel);

                if ($price !== null) {
                    $result[$poi->id][] = [
                        'fuel_type' => $price['fuel_type'] ?? array_search($fuel, self::FUELS, true),
                        'price' => $price['price'],
                        'service' => null,
                        'served_price' => $price['served_price'] ?? null,
                        'updated_at' => $price['updated_at'],
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * The average price of the usual fuels in the countries within bounds,
     * for the stations without a price of their own (such as in the
     * Netherlands, where no source of prices per station is free to use).
     *
     * @param  list<string>  $countries
     * @param  list<string>  $fuelTypes
     * @return array<string, list<array{fuel_type: string, price: float}>>
     */
    public function averages(array $countries, array $fuelTypes): array
    {
        $types = $fuelTypes ?: ['Unleaded 95 (E10)', 'Super Plus (E5)', 'Diesel', 'LPG'];
        $result = [];

        foreach (array_unique($countries) as $country) {
            foreach ($types as $type) {
                $fuel = self::FUELS[$type] ?? null;
                $price = $fuel === null || $fuel === 'cng' ? null : $this->prices->average($country, $type, $fuel);

                if ($price !== null) {
                    $result[$country][] = ['fuel_type' => $type, 'price' => round($price, 3)];
                }
            }
        }

        return $result;
    }

    /**
     * Fetches the tiles of the area that are not there yet, in the background:
     * true while that is going on, for the map to ask again in a while.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds
     */
    public function fetchMissing(array $bounds): bool
    {
        [$south, $west, $north, $east] = $bounds;
        $size = (float) config('trip_planner.tile_size');
        $tiles = [];

        for ($lat = $south; $lat <= $north + $size; $lat += $size) {
            for ($lon = $west; $lon <= $east + $size; $lon += $size) {
                $tiles[Geo::tile(min($lat, $north), min($lon, $east))] = true;

                if (count($tiles) > self::MAX_FETCH_TILES) {
                    return false;
                }
            }
        }

        $missing = $this->pois->missing(array_keys($tiles));

        if ($missing === []) {
            return false;
        }

        // A tile only once, however the map is moved over it meanwhile:
        // Overpass blocks whoever asks too much.
        $new = array_values(array_filter($missing, fn (string $tile) => Cache::add("station-map:fetching:{$tile}", true, now()->addMinutes(15))));

        if ($new !== []) {
            FetchPoiTiles::dispatch($new);
        }

        return true;
    }

    /**
     * @param  array{kinds: list<string>, fuels: list<string>, connectors: list<string>, priced: bool}  $filters
     */
    private function matches(Poi $poi, array $filters): bool
    {
        if ($poi->kind === Poi::GAS_STATION) {
            return $filters['fuels'] === [] || $poi->sells($filters['fuels']);
        }

        // A charging station whose connectors are not tagged may well have them.
        $connectors = $poi->connectors();

        return $filters['connectors'] === [] || $connectors === [] || array_intersect($connectors, $filters['connectors']) !== [];
    }

    /**
     * The country of a place: as it is tagged, or else the smallest of the
     * countries the trip planner covers whose box it lies in.
     */
    public function countryOf(Poi $poi): ?string
    {
        $tagged = strtoupper((string) ($poi->tags['addr:country'] ?? ''));

        if (strlen($tagged) === 2) {
            return $tagged;
        }

        $best = null;
        $bestArea = INF;

        foreach (config('trip_planner.warm.countries') as $country => [$south, $west, $north, $east]) {
            if ($poi->latitude >= $south && $poi->latitude <= $north && $poi->longitude >= $west && $poi->longitude <= $east) {
                $area = ($north - $south) * ($east - $west);

                if ($area < $bestArea) {
                    $best = $country;
                    $bestArea = $area;
                }
            }
        }

        return $best;
    }

    /**
     * The imported prices within bounds, by station of the source.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds
     * @return Collection<array-key, EloquentCollection<int, StationPrice>>
     */
    private function storedPrices(array $bounds): Collection
    {
        [$south, $west, $north, $east] = $bounds;
        $margin = 0.005;

        return StationPrice::query()
            ->whereBetween('latitude', [$south - $margin, $north + $margin])
            ->whereBetween('longitude', [$west - $margin, $east + $margin])
            ->get()
            ->groupBy(fn (StationPrice $price) => $price->source.':'.$price->source_id)
            ->toBase();
    }

    /**
     * The prices of the station of the source nearest to a place, if it is
     * near enough to be the same: self service first, served apart.
     *
     * @param  Collection<array-key, EloquentCollection<int, StationPrice>>  $stored
     * @return list<array<string, mixed>>
     */
    private function nearestPrices(Collection $stored, Poi $poi): array
    {
        $nearest = null;
        $nearestDistance = self::SAME_STATION;

        foreach ($stored as $prices) {
            $first = $prices->first();

            // Far off at a glance: no need to measure.
            if (abs($first->latitude - $poi->latitude) > 0.004 || abs($first->longitude - $poi->longitude) > 0.006) {
                continue;
            }

            $distance = Geo::distance((float) $poi->latitude, (float) $poi->longitude, $first->latitude, $first->longitude);

            if ($distance <= $nearestDistance) {
                $nearest = $prices;
                $nearestDistance = $distance;
            }
        }

        if ($nearest === null) {
            return [];
        }

        return $nearest->groupBy('fuel')
            ->map(function (Collection $prices) {
                $self = $prices->firstWhere('service', '!=', StationPrice::SERVED) ?? $prices->first();
                $served = $prices->firstWhere('service', StationPrice::SERVED);

                return [
                    'fuel_type' => $self->fuel_type,
                    'price' => $self->price,
                    'service' => $self->service,
                    'served_price' => $served !== null && $served !== $self ? $served->price : null,
                    'updated_at' => $self->reported_at?->toDateTimeString(),
                ];
            })
            ->sortBy('price')
            ->values()
            ->all();
    }

    /**
     * Only the fuels filtered on, when there is a filter.
     *
     * @param  list<array<string, mixed>>  $prices
     * @param  list<string>  $fuelTypes
     * @return list<array<string, mixed>>
     */
    private function onlyFuels(array $prices, array $fuelTypes): array
    {
        if ($fuelTypes === []) {
            return $prices;
        }

        $fuels = array_intersect_key(self::FUELS, array_flip($fuelTypes));

        return array_values(array_filter($prices, fn (array $price) => in_array(self::FUELS[$price['fuel_type']] ?? null, $fuels, true)));
    }

    /**
     * The last price the owner paid at each gas station of their refuelings,
     * by the name of the gas station as they wrote it down.
     *
     * @return Collection<int, array{words: non-empty-list<string>, price: float, fuel_type: string, date: string}>
     */
    private function ownPrices(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        return Refueling::query()
            ->whereIn('vehicle_id', $user->vehicles()->select('id'))
            ->whereNotNull('gas_station')
            ->where('unit_price', '>', 0)
            ->orderByDesc('date')
            ->limit(500)
            ->get(['gas_station', 'unit_price', 'fuel_type', 'date'])
            ->unique(fn (Refueling $refueling) => mb_strtolower(trim((string) $refueling->gas_station)))
            ->map(fn (Refueling $refueling) => [
                'words' => self::words((string) $refueling->gas_station),
                'price' => (float) $refueling->unit_price,
                'fuel_type' => (string) $refueling->fuel_type,
                'date' => $refueling->date->toDateString(),
            ])
            ->filter(fn (array $own) => $own['words'] !== [])
            ->values();
    }

    /**
     * The last price the owner paid at a gas station: of a refueling whose
     * gas station names every word of its name, or of its brand and a word of
     * its name. Refuelings have no location, so only by the name.
     *
     * @param  Collection<int, array{words: non-empty-list<string>, price: float, fuel_type: string, date: string}>  $own
     * @return array{price: float, fuel_type: string, date: string}|null
     */
    private function ownPriceAt(Collection $own, Poi $poi): ?array
    {
        $name = self::words((string) $poi->name);
        $brand = self::words((string) $poi->brand);

        if ($name === []) {
            return null;
        }

        $match = $own->first(fn (array $refueling) => (count($name) >= 2 && array_diff($name, $refueling['words']) === [])
            || ($brand !== [] && array_diff($brand, $refueling['words']) === [] && array_intersect(array_diff($name, $brand), $refueling['words']) !== []));

        return $match === null ? null : ['price' => $match['price'], 'fuel_type' => $match['fuel_type'], 'date' => $match['date']];
    }

    /**
     * The words of a name that tell it apart, in lower case.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($name), $matches);

        return array_values(array_unique(array_diff($matches[0], ['tankstation', 'station', 'tank', 'the'])));
    }
}
