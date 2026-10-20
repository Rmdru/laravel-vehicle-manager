<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\Poi;
use Illuminate\Support\Collection;

/**
 * The places along a route: where along it they are, how far from it, and on
 * which side.
 */
class Corridor
{
    /** Countries where traffic keeps left, by ISO 3 code. */
    private const LEFT_HAND_TRAFFIC = ['GBR', 'IRL', 'CYP', 'MLT', 'IMN', 'JEY', 'GGY'];

    /** A place this close to the route can be reached from both sides. */
    private const BOTH_SIDES = 10;

    /** The compass bearing of the directions OpenStreetMap tags a service area with. */
    private const DIRECTIONS = [
        'north' => 0, 'northbound' => 0, 'n' => 0,
        'east' => 90, 'eastbound' => 90, 'e' => 90,
        'south' => 180, 'southbound' => 180, 's' => 180,
        'west' => 270, 'westbound' => 270, 'w' => 270,
    ];

    /** The size of the cells the route is indexed by, in degrees: the cells around a place cover the map corridor. */
    private const CELL = 0.015;

    /** @var array<string, array<int, true>> The segments of the route by the cells they cross. */
    private array $grid = [];

    /** @var list<string>|null The fuel types of the vehicle the stops are for. */
    private ?array $fuelTypes = null;

    public function __construct(private Route $route)
    {
        $points = $route->points;

        for ($index = 0, $count = count($points) - 1; $index < $count; $index++) {
            [$lat1, $lon1] = $points[$index];
            [$lat2, $lon2] = $points[$index + 1];
            // A long straight segment crosses several cells.
            $steps = max(1, (int) ceil(max(abs($lat2 - $lat1), abs($lon2 - $lon1)) / (self::CELL / 2)));

            for ($step = 0; $step <= $steps; $step++) {
                $this->grid[$this->cell($lat1 + ($lat2 - $lat1) * $step / $steps, $lon1 + ($lon2 - $lon1) * $step / $steps)][$index] = true;
            }
        }
    }

    /**
     * The places within a distance of the route, with where along it they are.
     *
     * @param  Collection<int, Poi>  $pois
     * @return Collection<int, array{poi: Poi, along: float, offset: float, index: int, side: string}>
     */
    public function within(Collection $pois, float $maxOffset): Collection
    {
        return $pois
            ->map(fn (Poi $poi) => $this->locate($poi, $maxOffset))
            ->filter()
            ->sortBy('along')
            ->values();
    }

    /**
     * The places a stop can be planned at: the service and rest areas with the
     * amenities around them on the side of the road the car drives on, the gas
     * stations along other roads, and the gas stations near a motorway exit,
     * with the detour to them.
     *
     * @param  Collection<int, Poi>  $pois
     * @param  list<string>|null  $fuelTypes  The fuel types of the vehicle: a gas station without any of them is no place to refuel.
     * @return list<array<string, mixed>>
     */
    public function stops(Collection $pois, ?array $fuelTypes = null): array
    {
        $this->fuelTypes = $fuelTypes;
        $near = $this->within($pois, (float) config('trip_planner.corridor.fetch'));
        $areas = $near->filter(fn (array $located) => in_array($located['poi']->kind, [Poi::SERVICE_AREA, Poi::REST_AREA], true));
        $radius = (float) config('trip_planner.service_area_radius');
        // About the radius in degrees, to pass over the areas that are far away quickly.
        $degrees = $radius / 111320 * 2;
        $amenities = [];
        $loose = [];

        foreach ($near as $located) {
            $poi = $located['poi'];
            $amenity = $located;

            // A hotel is a place of its own, even at a service area.
            if ($poi->kind === Poi::HOTEL) {
                $loose[$poi->id] = $located;

                continue;
            }

            if (! in_array($poi->kind, [Poi::GAS_STATION, Poi::CHARGER, Poi::RESTAURANT, Poi::FAST_FOOD, Poi::CAFE, Poi::TOILETS, Poi::FACILITY], true)
                || $located['offset'] > (float) config('trip_planner.corridor.map')) {
                continue;
            }

            $nearest = null;
            $nearestDistance = $radius;

            foreach ($areas as $area) {
                // Service areas on both sides of the road can lie close together:
                // an amenity belongs to the one on its own side.
                if (! $this->sameSide($area, $amenity)) {
                    continue;
                }

                $area = $area['poi'];

                if (abs($area->latitude - $poi->latitude) > $degrees || abs($area->longitude - $poi->longitude) > $degrees * 2) {
                    continue;
                }

                $distance = Geo::distance($area->latitude, $area->longitude, $poi->latitude, $poi->longitude);

                if ($distance <= $nearestDistance) {
                    $nearest = $area;
                    $nearestDistance = $distance;
                }
            }

            if ($nearest) {
                $amenities[$nearest->id][] = $poi;
            } elseif (in_array($poi->kind, [Poi::GAS_STATION, Poi::CHARGER], true)) {
                $loose[$poi->id] = $located;
            }
            // A facility on its own is no place to stop.
        }

        // The gas stations further away, for the exits.
        foreach ($near as $located) {
            if (in_array($located['poi']->kind, [Poi::GAS_STATION, Poi::CHARGER], true) && ! isset($loose[$located['poi']->id]) && $located['offset'] > (float) config('trip_planner.corridor.map')) {
                $loose[$located['poi']->id] = $located;
            }
        }

        $exits = $near->filter(fn (array $located) => $located['poi']->kind === Poi::JUNCTION
            && $located['offset'] <= (float) config('trip_planner.prices.exit_on_route'));

        $stops = [];

        foreach ($areas as $located) {
            if ($located['offset'] > (float) config('trip_planner.corridor.service_area') || ! $this->reachable($located)) {
                continue;
            }

            /** @var Poi $area */
            $area = $located['poi'];
            $around = collect($amenities[$area->id] ?? []);
            $features = $around->flatMap(fn (Poi $poi) => $this->featuresOf($poi))->merge($this->featuresOf($area));

            // A service area with a restaurant or a gas station offers coffee too.
            if ($features->contains('meal') || $features->contains('fuel')) {
                $features->push('coffee');
            }

            $stops[] = $this->stop($located, $features->unique()->values()->all(), $around);
        }

        foreach ($loose as $located) {
            if (! $this->route->onMotorway($located['index'])) {
                if ($located['poi']->kind === Poi::HOTEL && $located['offset'] <= (float) config('trip_planner.stops.hotel_off_road')) {
                    $meters = 2 * 1.3 * $located['offset'];
                    $stops[] = [
                        ...$this->stop($located, $this->featuresOf($located['poi']), collect()),
                        'detour_meters' => (int) round($meters),
                        'detour_seconds' => (int) round($meters / (30 / 3.6)),
                    ];
                } elseif ($located['offset'] <= (float) config('trip_planner.corridor.gas_station') && $this->reachable($located)) {
                    $stops[] = $this->stop($located, $this->featuresOf($located['poi']), collect());
                }

                continue;
            }

            // Along a motorway, a gas station or a hotel is reached by the nearest exit: not a gas station without the fuel.
            if ($located['poi']->kind === Poi::GAS_STATION && ! $located['poi']->sells($this->fuelTypes)) {
                continue;
            }

            $exit = $this->exitTo($located, $exits);

            if ($exit) {
                $stops[] = $exit;
            }
        }

        usort($stops, fn (array $a, array $b) => $a['along'] <=> $b['along']);

        return $stops;
    }

    /**
     * What a place offers: no refueling where the fuel of the vehicle is not sold.
     *
     * @return list<string>
     */
    private function featuresOf(Poi $poi): array
    {
        $features = $poi->features();

        return $poi->sells($this->fuelTypes) ? $features : array_values(array_diff($features, ['fuel']));
    }

    /**
     * @param  array{poi: Poi, along: float, offset: float, index: int, side: string}  $located
     * @param  list<string>  $features
     * @param  Collection<int, Poi>  $around
     * @return array<string, mixed>
     */
    private function stop(array $located, array $features, Collection $around): array
    {
        $poi = $located['poi'];
        // The gas station that sells a fuel of the vehicle, if there is one.
        $gasStation = $poi->kind === Poi::GAS_STATION ? $poi : ($around->first(fn (Poi $other) => $other->kind === Poi::GAS_STATION && $other->sells($this->fuelTypes))
            ?? $around->firstWhere('kind', Poi::GAS_STATION));

        return [
            'poi_id' => $poi->id,
            'kind' => $poi->kind,
            'name' => $poi->name ?? $gasStation?->name,
            'brand' => $gasStation ? ($gasStation->brand ?? $gasStation->name) : null,
            'latitude' => $poi->latitude,
            'longitude' => $poi->longitude,
            'gas_station_id' => $gasStation?->id,
            'fuel_latitude' => $gasStation?->latitude,
            'fuel_longitude' => $gasStation?->longitude,
            'features' => array_values(array_intersect(Poi::FEATURES, $features)),
            'services' => array_values(array_unique(array_merge($poi->services(), ...$around->map(fn (Poi $other) => $other->services())->values()->all()))),
            // The fastest charger there, in kW.
            'power' => collect([$poi, ...$around->all()])->map(fn (Poi $other) => $other->power())->filter()->max(),
            'along' => $located['along'],
            'offset' => round($located['offset']),
            'country' => $this->route->countryAt($located['index']),
            'motorway' => $this->route->onMotorway($located['index']),
            'detour_meters' => 0,
            'detour_seconds' => 0,
        ];
    }

    /**
     * A stop at a gas station off the motorway, by the nearest exit to it: the
     * detour is there and back, a little longer than as the crow flies.
     *
     * @param  array{poi: Poi, along: float, offset: float, index: int, side: string}  $located
     * @param  Collection<int, array{poi: Poi, along: float, offset: float, index: int, side: string}>  $exits
     * @return array<string, mixed>|null
     */
    private function exitTo(array $located, Collection $exits): ?array
    {
        $station = $located['poi'];
        $nearest = null;
        $nearestDistance = (float) config('trip_planner.corridor.fetch');

        foreach ($exits as $exit) {
            if (abs($exit['along'] - $located['along']) > 2 * $nearestDistance) {
                continue;
            }

            $distance = Geo::distance($exit['poi']->latitude, $exit['poi']->longitude, $station->latitude, $station->longitude);

            if ($distance <= $nearestDistance) {
                $nearest = $exit;
                $nearestDistance = $distance;
            }
        }

        if ($nearest === null) {
            return null;
        }

        $meters = 2 * 1.3 * $nearestDistance;
        // At about 50 km/h off the motorway, and the time to leave it and get back onto it.
        $seconds = $meters / (50 / 3.6) + (float) config('trip_planner.prices.exit_seconds');

        $maxMinutes = $station->kind === Poi::HOTEL
            ? config('trip_planner.stops.hotel_max_detour_minutes')
            : config('trip_planner.prices.max_detour_minutes');

        if ($seconds > (float) $maxMinutes * 60) {
            return null;
        }

        return [
            ...$this->stop([...$nearest, 'poi' => $station], $this->featuresOf($station), collect()),
            'latitude' => $station->latitude,
            'longitude' => $station->longitude,
            'offset' => round($located['offset']),
            'motorway' => false,
            'exit' => trim(($nearest['poi']->tags['ref'] ?? '').' '.($nearest['poi']->name ?? '')) ?: null,
            'detour_meters' => (int) round($meters),
            'detour_seconds' => (int) round($seconds),
        ];
    }

    /**
     * Whether a place lies on the side of the road the car drives on, or so
     * close to it that it is reachable from both sides.
     *
     * @param  array{poi: Poi, along: float, offset: float, index: int, side: string}  $located
     */
    private function reachable(array $located): bool
    {
        // The direction the service area is for, where it is tagged with one.
        $direction = self::DIRECTIONS[strtolower((string) ($located['poi']->tags['direction'] ?? ''))] ?? null;

        if ($direction !== null) {
            $difference = abs($this->bearing($located['index']) - $direction);

            return min($difference, 360 - $difference) <= 90;
        }

        $keepLeft = in_array($this->route->countryAt($located['index']), self::LEFT_HAND_TRAFFIC, true);

        return $located['offset'] <= self::BOTH_SIDES || $located['side'] === ($keepLeft ? 'left' : 'right');
    }

    /**
     * Whether two places lie on the same side of the road, or one of them on it.
     *
     * @param  array{poi: Poi, along: float, offset: float, index: int, side: string}  $a
     * @param  array{poi: Poi, along: float, offset: float, index: int, side: string}  $b
     */
    private function sameSide(array $a, array $b): bool
    {
        return $a['side'] === $b['side'] || $a['offset'] <= self::BOTH_SIDES || $b['offset'] <= self::BOTH_SIDES;
    }

    /**
     * The compass bearing of the route at the segment from a point, in degrees.
     */
    private function bearing(int $index): float
    {
        [$lat1, $lon1] = array_map('deg2rad', $this->route->points[$index]);
        [$lat2, $lon2] = array_map('deg2rad', $this->route->points[min($index + 1, count($this->route->points) - 1)]);

        $y = sin($lon2 - $lon1) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lon2 - $lon1);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * @return array{poi: Poi, along: float, offset: float, index: int, side: string}|null
     */
    private function locate(Poi $poi, float $maxOffset): ?array
    {
        $located = $this->locatePoint($poi->latitude, $poi->longitude, $maxOffset);

        return $located ? ['poi' => $poi, ...$located] : null;
    }

    /**
     * Where along the route a point is, how far from it, and on which side,
     * when it is within a distance of it.
     *
     * @return array{along: float, offset: float, index: int, side: string}|null
     */
    public function locatePoint(float $latitude, float $longitude, float $maxOffset): ?array
    {
        $point = [$latitude, $longitude];
        [$y, $x] = $this->cellOf($latitude, $longitude);
        $best = null;
        // Enough cells around the point to cover the distance.
        $reach = max(1, (int) ceil($maxOffset / (self::CELL * 111320 * cos(deg2rad($latitude)))));

        for ($dy = -$reach; $dy <= $reach; $dy++) {
            for ($dx = -$reach; $dx <= $reach; $dx++) {
                foreach (array_keys($this->grid[($y + $dy).':'.($x + $dx)] ?? []) as $index) {
                    $start = $this->route->points[$index];
                    $end = $this->route->points[$index + 1];
                    $offset = Geo::distanceToSegment($point, $start, $end);

                    if ($offset <= $maxOffset && ($best === null || $offset < $best['offset'])) {
                        $best = ['index' => $index, 'offset' => $offset, 'start' => $start, 'end' => $end];
                    }
                }
            }
        }

        if ($best === null) {
            return null;
        }

        [$px, $py] = Geo::project($point, $best['start'][0]);
        [$x1, $y1] = Geo::project($best['start'], $best['start'][0]);
        [$x2, $y2] = Geo::project($best['end'], $best['start'][0]);
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lengthSquared = $dx * $dx + $dy * $dy;
        $t = $lengthSquared > 0 ? max(0, min(1, (($px - $x1) * $dx + ($py - $y1) * $dy) / $lengthSquared)) : 0;
        $index = $best['index'];

        return [
            'along' => $this->route->distances[$index] + $t * ($this->route->distances[$index + 1] - $this->route->distances[$index]),
            'offset' => $best['offset'],
            'index' => $index,
            // Seen in the direction of travel.
            'side' => $dx * ($py - $y1) - $dy * ($px - $x1) > 0 ? 'left' : 'right',
        ];
    }

    private function cell(float $lat, float $lon): string
    {
        return implode(':', $this->cellOf($lat, $lon));
    }

    /**
     * @return array{int, int}
     */
    private function cellOf(float $lat, float $lon): array
    {
        return [(int) floor($lat / self::CELL), (int) floor($lon / self::CELL)];
    }
}
