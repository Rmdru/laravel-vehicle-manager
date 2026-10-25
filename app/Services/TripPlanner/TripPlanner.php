<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\Poi;
use App\Models\Trip;
use App\Models\TripWaypoint;
use App\Models\Vehicle;
use App\Services\TollSections;
use App\Support\Roads;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TripPlanner
{
    /** A toll booth this close to the route is passed through. */
    private const TOLL_BOOTH_ON_ROUTE = 60;

    /** Toll roads less far apart than this, in meters, that come to the same section are paid once. */
    private const TOLL_GAP = 2000;

    /** The latest refuelings an estimate of the fuel price is the average of. */
    private const RECENT_REFUELINGS = 5;

    public function __construct(
        private RoutingProvider $routing,
        private PoiRepository $pois,
        private FuelPrices $prices,
        private TripNotices $notices,
        private TomTomTrafficService $traffic,
        private TollSections $tollSections,
    ) {}

    /**
     * Reads the prices of the gas stations anew, rather than taking them
     * from what was kept: when the driver refreshes them.
     */
    public function freshPrices(): static
    {
        $this->prices->fresh = true;

        return $this;
    }

    /**
     * The fastest route of a trip and its alternatives. They are kept for a
     * while: choosing another stop plans the trip again with the same routes.
     *
     * @param  bool  $fresh  Planned anew, rather than taken from what was kept.
     * @return list<Route>
     *
     * @throws RoutingException
     */
    public function routes(Trip $trip, bool $fresh = false): array
    {
        $waypoints = $trip->waypoints->map(fn (TripWaypoint $waypoint) => [(float) $waypoint->latitude, (float) $waypoint->longitude])->all();
        $time = CarbonImmutable::parse($trip->time_at)->setTimezone(config('trip_planner.timezone'));
        $alternatives = (int) config('trip_planner.alternatives');
        $key = 'trip-planner:routes:'.md5((string) json_encode([$waypoints, $trip->time_mode, $time->toIso8601String(), $alternatives]));
        $routes = $fresh ? null : Cache::get($key);

        if (! is_array($routes)) {
            $routes = $this->routing->routes($waypoints, $trip->time_mode, $time, $alternatives);

            if ($routes) {
                Cache::put($key, $routes, now()->addMinutes((int) config('trip_planner.tomtom.routes_cache_minutes')));
            }
        }

        if (! $routes) {
            throw new RoutingException(__('There is no route between these places.'));
        }

        return $routes;
    }

    /**
     * The places along routes; nothing when they could not be fetched.
     *
     * @param  list<Route>  $routes
     * @param  (Closure(float, string, int, int): void)|null  $progress  Called with the part of the fetching that is done, the stage and its requests done and in all.
     * @param  float|null  $deadline  The moment (as microtime) the fetching stops: the rest is fetched another time.
     * @return Collection<int, Poi>|null
     */
    public function places(array $routes, ?Closure $progress = null, ?float $deadline = null): ?Collection
    {
        $pois = $this->pois->along(array_merge(...array_map(fn (Route $route) => $route->points, $routes)), $progress, $deadline);

        return $this->pois->failed && $pois->isEmpty() ? null : $pois;
    }

    /**
     * The road works, closures and, when leaving soon, the jams along each route.
     *
     * @param  list<Route>  $routes
     * @param  bool  $fresh  Asked anew, rather than taken from what was kept.
     * @return list<list<array<string, mixed>>>
     */
    public function traffic(Trip $trip, array $routes, bool $fresh = false): array
    {
        return array_map(function (Route $route) use ($trip, $fresh) {
            $departure = $trip->time_mode === 'arrival'
                ? CarbonImmutable::parse($trip->time_at)->subSeconds((int) round($route->driveSeconds))
                : $route->departure;

            // The stops are not known yet: a few hours of them at most.
            return $this->traffic->along($route, $departure, $departure->addSeconds((int) round($route->driveSeconds))->addHours(3), app()->getLocale(), $fresh);
        }, $routes);
    }

    /**
     * The plan of the routes of a trip: with the stops along them once the
     * places along them are known.
     *
     * @param  list<Route>  $routes
     * @param  Collection<int, Poi>|null  $pois
     * @param  list<list<array<string, mixed>>>  $traffic  The traffic along each route.
     * @return array<string, mixed>
     */
    public function plan(Trip $trip, array $routes, ?Collection $pois, array $traffic = []): array
    {
        $range = VehicleRange::of($trip->vehicle);

        return [
            'vehicle' => [
                'consumption' => $range?->consumption,
                'tank' => $range?->tank,
                'from_refuelings' => $range?->fromRefuelings,
                'fuel_type' => $range?->fuelType,
                'electric' => str_starts_with((string) $trip->vehicle?->powertrain, 'electricity'),
            ],
            'loading' => $pois === null && ! $this->pois->failed,
            // The daily limit of TomTom is near: the traffic was left out.
            'traffic_skipped' => $this->traffic->skipped,
            'routes' => array_map(
                fn (Route $route, int $index) => [...$this->route($trip, $route, $pois, $range), 'traffic' => $traffic[$index] ?? []],
                $routes,
                array_keys($routes),
            ),
        ];
    }

    /**
     * @param  Collection<int, Poi>|null  $pois
     * @return array<string, mixed>
     */
    private function route(Trip $trip, Route $route, ?Collection $pois, ?VehicleRange $range): array
    {
        $planned = ['stops' => [], 'warnings' => $pois === null && $this->pois->failed ? ['no_places'] : []];
        $corridor = new Corridor($route);
        $candidates = [];

        if ($pois !== null) {
            // Not where the driver chose not to stop.
            $excluded = array_map('intval', (array) $trip->excluded_stops);
            $candidates = array_values(array_filter($corridor->stops($pois, $range?->electric ? null : array_values((array) $trip->vehicle?->fuel_types)), fn (array $candidate) => ! in_array($candidate['poi_id'], $excluded, true)));
            // A price is only looked up for the gas stations a fuel stop is considered at.
            $priceOf = $range && ! $range->electric ? fn (array $candidate) => $this->prices->of($candidate, $range)['price'] ?? null : null;
            $pinned = array_map('intval', (array) $trip->pinned_stops);

            $plan = fn (?CarbonImmutable $departure) => (new StopPlanner(
                $route, $candidates, $this->waypoints($trip, $route), $range, $trip->fuel_level / 100, $priceOf, $departure, $pinned,
            ))->plan();

            if ($trip->time_mode === 'arrival') {
                // The departure, which the meals depend on, follows from the stops:
                // planned again until it no longer changes.
                $arrival = CarbonImmutable::parse($trip->time_at);
                $departure = $arrival->subSeconds((int) round($route->driveSeconds));

                $best = null;

                for ($pass = 0; $pass < 6; $pass++) {
                    $attempt = $plan($departure);
                    $next = $arrival->subSeconds((int) round($route->driveSeconds) + $this->extraSeconds($attempt['stops']));
                    $off = abs($next->getTimestamp() - $departure->getTimestamp());

                    // When it keeps changing, the plan that is off the least.
                    if ($best === null || $off < $best[0]) {
                        $best = [$off, $attempt];
                    }

                    if ($off < 60) {
                        break;
                    }

                    $departure = $next;
                }

                $planned = $best[1];
            } else {
                $planned = $plan(null);
            }

            if ($this->pois->failed) {
                $planned['warnings'][] = 'some_places';
            }
        }

        $extraSeconds = $this->extraSeconds($planned['stops']);

        if ($trip->time_mode === 'arrival') {
            $arrival = CarbonImmutable::parse($trip->time_at);
            $departure = $arrival->subSeconds((int) round($route->driveSeconds) + $extraSeconds);
        } else {
            $departure = $route->departure;
            $arrival = $departure->addSeconds((int) round($route->driveSeconds) + $extraSeconds);
        }

        $stops = [];
        $before = 0;

        foreach ($planned['stops'] as $stop) {
            $arriveAt = $departure->addSeconds((int) round($stop['drive_seconds'] + $before + ($stop['detour_seconds'] ?? 0) / 2));
            $before += $stop['minutes'] * 60 + ($stop['detour_seconds'] ?? 0);
            $road = $route->roadAt($route->indexAt($stop['along']));

            $stops[] = [
                ...$stop,
                'along' => (int) round($stop['along']),
                'road' => $road ? Roads::badge(CountryCodes::iso2((string) $road['country']), $road['ref']) : null,
                'country' => isset($stop['country']) ? CountryCodes::iso2($stop['country']) : null,
                'pinned' => in_array($stop['poi_id'] ?? null, (array) $trip->pinned_stops, true),
                'arrive_at' => $arriveAt->toIso8601String(),
                'depart_at' => $arriveAt->addMinutes($stop['minutes'])->toIso8601String(),
            ];
        }

        $nearby = $pois === null ? collect() : $corridor->within($pois, (float) config('trip_planner.corridor.map'));
        $prices = $this->prices->known(array_column($candidates, 'poi_id'));
        $point = fn (int $index) => ['latitude' => $route->points[$index][0], 'longitude' => $route->points[$index][1]];
        $countries = array_values(array_unique(array_map(CountryCodes::iso2(...), array_column($route->countries, 'country'))));
        $vignettes = array_values(array_unique(array_map(CountryCodes::iso2(...), array_filter(array_column($route->vignettes, 'country')))));
        $destinations = array_values(array_filter(array_map(
            fn (int $index) => ($country = $route->countryAt($index)) ? CountryCodes::iso2($country) : null,
            $route->legEnds,
        )));

        return [
            'distance' => (int) round($route->distance),
            'drive_seconds' => (int) round($route->driveSeconds),
            'stop_seconds' => $extraSeconds,
            'traffic_delay' => (int) round($route->trafficDelay),
            'departure_at' => $departure->toIso8601String(),
            'arrival_at' => $arrival->toIso8601String(),
            'roads' => array_map(fn (array $road) => Roads::badge(CountryCodes::iso2((string) $road['country']), $road['ref']), $route->roads),
            'countries' => $countries,
            'toll_distance' => (int) round(array_sum(array_map(
                fn (array $toll) => $route->distances[$toll['end']] - $route->distances[$toll['start']],
                $route->tollRoads,
            ))),
            'vignettes' => $vignettes,
            // What the driver should know about the vehicle for this trip.
            'notices' => $this->notices->for($trip, $countries, $vignettes, $destinations, $route->distance, $departure, $arrival),
            'geometry' => Geo::encodePolyline(array_map(
                fn (int $index) => $route->points[$index],
                Geo::simplify($route->points, 15),
            )),
            // Where it is usually busy at the time it is driven.
            'congestion' => array_map(fn (array $stretch) => [
                'level' => $stretch['level'],
                'delay' => $stretch['delay'],
                'length' => (int) round($route->distances[$stretch['end']] - $route->distances[$stretch['start']]),
                'along' => (int) round($route->distances[$stretch['start']]),
                // Where its icon goes: halfway.
                ...$point(intdiv($stretch['start'] + $stretch['end'], 2)),
                'road_badge' => ($road = $route->roadAt(intdiv($stretch['start'] + $stretch['end'], 2)))
                    ? Roads::badge(CountryCodes::iso2((string) $road['country']), $road['ref'])
                    : null,
                'geometry' => Geo::encodePolyline(array_map(
                    fn (int $index) => $route->points[$stretch['start'] + $index],
                    Geo::simplify(array_slice($route->points, $stretch['start'], $stretch['end'] - $stretch['start'] + 1), 15),
                )),
            ], $route->congestion),
            // Where the toll roads begin and end.
            'toll_points' => array_merge(...array_map(fn (array $toll) => [
                ['type' => 'start', ...$point($toll['start'])],
                ['type' => 'end', ...$point($toll['end'])],
            ], $route->tollRoads) ?: [[]]),
            'stops' => $pois === null ? null : $stops,
            'warnings' => array_values(array_unique($planned['warnings'])),
            // The places on the map, by their ids: where a stop could be made,
            // the toll booths passed through, and everything else nearby.
            'candidates' => array_map(fn (array $candidate) => array_filter([
                'id' => $candidate['poi_id'],
                'features' => $candidate['features'],
                'services' => $candidate['services'] ?? [],
                'brand' => $candidate['brand'] ?? null,
                'gas_station_id' => $candidate['gas_station_id'] ?? null,
                'exit' => $candidate['exit'] ?? null,
                'detour_seconds' => $candidate['detour_seconds'] ?? 0,
            ], fn ($value) => $value !== null), $candidates),
            'toll_booths' => $nearby
                ->filter(fn (array $located) => $located['poi']->kind === Poi::TOLL_BOOTH && $located['offset'] <= self::TOLL_BOOTH_ON_ROUTE)
                ->map(fn (array $located) => $located['poi']->id)
                ->values()
                ->all(),
            'pois' => $nearby
                ->reject(fn (array $located) => in_array($located['poi']->kind, [Poi::JUNCTION, Poi::FACILITY], true))
                ->map(fn (array $located) => $located['poi']->id)
                ->values()
                ->all(),
            'prices' => $prices,
            'costs' => $this->costs($trip, $route, $range, $pois === null ? null : $stops, $prices, $vignettes, $departure, $arrival),
        ];
    }

    /**
     * The fuel type a trip is estimated at: an electric vehicle charges at
     * fast chargers on the way, not at the cheaper slow ones near home.
     */
    private function estimatedFuelType(VehicleRange $range): ?string
    {
        return $range->electric ? 'Electricity DC' : $range->fuelType;
    }

    /**
     * The average price per liter, or per kWh, of the latest refuelings of a
     * vehicle with the fuel of the trip, leaving out a typo or a price of
     * another unit; null when it has none.
     */
    private function recentUnitPrice(?Vehicle $vehicle, VehicleRange $range): ?float
    {
        $fuelType = $this->estimatedFuelType($range);

        if ($vehicle === null || $fuelType === null) {
            return null;
        }

        [$least, $most] = config("powertrain_comparison.price_bounds.{$fuelType}", [0.01, PHP_FLOAT_MAX]);

        $price = $vehicle->refuelings()
            ->newest()
            ->where('fuel_type', $fuelType)
            ->whereBetween('unit_price', [$least, $most])
            ->limit(self::RECENT_REFUELINGS)
            ->pluck('unit_price')
            ->avg();

        return $price !== null ? (float) $price : null;
    }

    /**
     * What a route costs, as far as it is known: the fuel, at the prices of
     * the fuel stops or else at those along the route, the vignettes, at the
     * price paid for the last one of the country, and the toll roads, at what
     * the vehicle usually paid on a section of the same road.
     *
     * @param  list<array<string, mixed>>|null  $stops  Null while the stops are not known yet.
     * @param  array<int, array{price: float}>  $prices
     * @param  list<string>  $vignettes  ISO 2 codes.
     * @return array{fuel: ?float, fuel_price: ?float, fuel_estimate: ?string, energy: ?float, vignettes: list<array{country: string, price: ?float, owned: bool}>, tolls: list<array{distance: int, label: ?string, price: ?float, passages: int}>, total: ?float, complete: bool}
     */
    private function costs(Trip $trip, Route $route, ?VehicleRange $range, ?array $stops, array $prices, array $vignettes, CarbonImmutable $departure, CarbonImmutable $arrival): array
    {
        // The liters, or the kWh, the route takes.
        $energy = $range ? round($route->distance / 1000 * $range->consumption / 100, 1) : null;
        $price = null;
        $fuel = null;

        if ($energy !== null && ! $range->electric && $stops !== null) {
            // The price at each fuel stop, by the fuel refueled there.
            $paid = array_values(array_filter(array_map(
                fn (array $stop) => isset($stop['poi_id'], $prices[$stop['poi_id']]) && in_array('fuel', (array) ($stop['needs'] ?? []), true)
                    ? [(float) $prices[$stop['poi_id']]['price'], max(1.0, (float) ($stop['fuel_liters'] ?? 0))]
                    : null,
                $stops,
            )));

            $price = match (true) {
                $paid !== [] => array_sum(array_map(fn (array $pair) => $pair[0] * $pair[1], $paid)) / array_sum(array_column($paid, 1)),
                $prices !== [] => array_sum(array_column($prices, 'price')) / count($prices),
                default => null,
            };
            $fuel = $price !== null ? round($price * $energy, 2) : null;
        }

        $vehicle = $trip->vehicle;

        // Without a price on the route (yet), or for charging: an estimate, at
        // the average prices of the countries of the route, or else at what
        // the vehicle was refueled or charged for lately, or else at a usual price.
        $estimate = null;

        if ($energy !== null && $price === null) {
            $typical = config('powertrain_comparison.fallback_prices.'.$this->estimatedFuelType($range));

            [$price, $estimate] = match (true) {
                ! $range->electric && ($average = $this->prices->averageAlong($route, $range)) !== null => [$average, 'countries'],
                ($own = $this->recentUnitPrice($vehicle, $range)) !== null => [$own, 'refuelings'],
                is_numeric($typical) => [(float) $typical, 'typical'],
                default => [null, null],
            };
            $fuel = $price !== null ? round($price * $energy, 2) : null;
        }

        $vignetteCosts = array_map(function (string $country) use ($trip, $vehicle, $departure, $arrival) {
            // A vignette that holds for the trip is paid for already.
            $owned = $vehicle !== null && $this->notices->holds($vehicle->vignettes, $country, $departure, $arrival);

            return ['country' => $country, 'price' => $owned ? 0.0 : $this->lastVignettePrice($trip, $country), 'owned' => $owned];
        }, $vignettes);

        $tolls = $this->tolls($trip, $route);
        $known = array_filter([$fuel, ...array_column($vignetteCosts, 'price'), ...array_column($tolls, 'price')], fn (?float $value) => $value !== null);

        return [
            'fuel' => $fuel,
            'fuel_price' => $price !== null ? round($price, 3) : null,
            // How the fuel was estimated, or null when it is at the prices on the route.
            'fuel_estimate' => $estimate,
            'energy' => $energy,
            'vignettes' => $vignetteCosts,
            'tolls' => $tolls,
            'total' => $known !== [] ? round(array_sum($known), 2) : null,
            'complete' => $fuel !== null && ! in_array(null, array_column($vignetteCosts, 'price'), true) && ! in_array(null, array_column($tolls, 'price'), true),
        ];
    }

    /**
     * The toll roads of a route with what they cost, by the passages of the
     * vehicle on the roads the route takes there. Toll roads close together
     * that come to the same section are one: the routing provider can split
     * a section up.
     *
     * @return list<array{distance: int, label: ?string, price: ?float, passages: int}>
     */
    private function tolls(Trip $trip, Route $route): array
    {
        $tolls = [];
        // The end of the toll road before, along the route.
        $end = null;

        foreach ($route->tollRoads as $toll) {
            $roads = array_values(array_unique(array_filter(array_merge(
                [$route->roadAt($toll['start'])['ref'] ?? null],
                array_column(array_filter($route->roadRuns, fn (array $run) => $run['start'] > $toll['start'] && $run['start'] < $toll['end']), 'ref'),
            ))));
            $country = $route->countryAt($toll['start']);
            $section = $trip->vehicle !== null && $country !== null && $roads !== []
                ? $this->tollSections->priceOn($trip->vehicle, CountryCodes::iso2($country), $roads)
                : null;
            $distance = (int) round($route->distances[$toll['end']] - $route->distances[$toll['start']]);
            $last = array_key_last($tolls);
            $close = $end !== null && $route->distances[$toll['start']] - $end < self::TOLL_GAP;
            $end = $route->distances[$toll['end']];

            if ($section !== null && $last !== null && $close && $tolls[$last]['label'] === $section['label']) {
                $tolls[$last]['distance'] += $distance;

                continue;
            }

            $tolls[] = [
                'distance' => $distance,
                'label' => $section['label'] ?? null,
                'price' => $section['price'] ?? null,
                'passages' => $section['passages'] ?? 0,
            ];
        }

        return $tolls;
    }

    /**
     * The price the vehicle paid for its last vignette of a country.
     */
    private function lastVignettePrice(Trip $trip, string $country): ?float
    {
        $key = collect(config('countries'))->search(fn (array $config) => $config['iso_code'] === $country);
        $price = $trip->vehicle?->vignettes
            ->where('country', $key)
            ->whereNotNull('price')
            ->sortByDesc('start_date')
            ->first()?->price;

        return $price !== null ? (float) $price : null;
    }

    /**
     * The time the stops take, the detours to them included, in seconds.
     *
     * @param  list<array<string, mixed>>  $stops
     */
    private function extraSeconds(array $stops): int
    {
        return array_sum(array_column($stops, 'minutes')) * 60
            + (int) round(array_sum(array_map(fn (array $stop) => $stop['detour_seconds'] ?? 0, $stops)));
    }

    /**
     * The destinations along the way, with where along the route they are.
     *
     * @return list<array{along: float, stay: int, label: string}>
     */
    private function waypoints(Trip $trip, Route $route): array
    {
        $stays = $trip->waypoints->slice(1, -1)->values();
        $waypoints = [];

        foreach (array_slice($route->legEnds, 0, -1) as $n => $index) {
            $waypoints[] = [
                'along' => $route->distances[$index],
                'stay' => (int) ($stays[$n]->stay_minutes ?? 0),
                'label' => (string) ($stays[$n]->label ?? ''),
            ];
        }

        return $waypoints;
    }
}
