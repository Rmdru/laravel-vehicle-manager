<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\Poi;
use App\Models\Trip;
use App\Models\TripWaypoint;
use App\Support\Roads;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;

class TripPlanner
{
    /** A toll booth this close to the route is passed through. */
    private const TOLL_BOOTH_ON_ROUTE = 60;

    public function __construct(
        private RoutingProvider $routing,
        private PoiRepository $pois,
        private FuelPrices $prices,
        private TripNotices $notices,
        private TomTomTrafficService $traffic,
    ) {}

    /**
     * The fastest route of a trip and its alternatives.
     *
     * @return list<Route>
     *
     * @throws RoutingException
     */
    public function routes(Trip $trip): array
    {
        $routes = $this->routing->routes(
            $trip->waypoints->map(fn (TripWaypoint $waypoint) => [$waypoint->latitude, $waypoint->longitude])->all(),
            $trip->time_mode,
            CarbonImmutable::parse($trip->time_at)->setTimezone(config('trip_planner.timezone')),
            (int) config('trip_planner.alternatives'),
        );

        if (! $routes) {
            throw new RoutingException(__('There is no route between these places.'));
        }

        return $routes;
    }

    /**
     * The places along routes; nothing when they could not be fetched.
     *
     * @param  list<Route>  $routes
     * @param  (Closure(float): void)|null  $progress  Called with the part of the fetching that is done.
     * @return Collection<int, Poi>|null
     */
    public function places(array $routes, ?Closure $progress = null): ?Collection
    {
        $pois = $this->pois->along(array_merge(...array_map(fn (Route $route) => $route->points, $routes)), $progress);

        return $this->pois->failed && $pois->isEmpty() ? null : $pois;
    }

    /**
     * The road works, closures and, when leaving soon, the jams along each route.
     *
     * @param  list<Route>  $routes
     * @return list<list<array<string, mixed>>>
     */
    public function traffic(Trip $trip, array $routes): array
    {
        return array_map(function (Route $route) use ($trip) {
            $departure = $trip->time_mode === 'arrival'
                ? CarbonImmutable::parse($trip->time_at)->subSeconds((int) round($route->driveSeconds))
                : $route->departure;

            // The stops are not known yet: a few hours of them at most.
            return $this->traffic->along($route, $departure, $departure->addSeconds((int) round($route->driveSeconds))->addHours(3), app()->getLocale());
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
            'prices' => $this->prices->known(array_column($candidates, 'poi_id')),
        ];
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
