<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Support\Roads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The traffic along a route: road works and closures, planned ones as well,
 * and, when leaving soon, jams and accidents.
 */
class TomTomTrafficService
{
    /** The kinds of incidents TomTom tells, by their icon category. */
    private const CATEGORIES = [
        1 => 'accident', 3 => 'danger', 6 => 'jam', 7 => 'lane_closed', 8 => 'closed', 9 => 'road_works', 14 => 'broken_down',
    ];

    /** Incidents that are over soon: only of interest when leaving soon. */
    private const CURRENT = ['accident', 'danger', 'jam', 'broken_down'];

    /** Road works and closures without a known end are only told for a trip within this many days: later they may well be over. */
    private const OPEN_ENDED_DAYS = 7;

    /** An incident this close to the route is on it, in meters. */
    private const ON_ROUTE = 60;

    /** The largest box TomTom looks in, in degrees on each side: rounded out, still within its 10,000 km² in the south of Europe. */
    private const BOX = 0.75;

    /** The kinds of traffic drawn as a line along the road: road works and closures. */
    private const LINES = ['road_works', 'lane_closed', 'closed'];

    /** The most points a line of road works or a closure keeps. */
    private const MAX_LINE_POINTS = 80;

    /** The boxes are rounded out to this many degrees: the alternatives and a trip planned again then ask for the same ones. */
    private const ROUND_BOX = 0.1;

    /** Whether the traffic was left out, as the daily limit of TomTom is near. */
    public bool $skipped = false;

    /**
     * @param  bool  $fresh  Asked anew, rather than taken from what was kept.
     * @return list<array{category: string, description: string, road: ?string, road_badge: array{label: string, classes: string}|null, line: list<array{float, float}>|null, from: ?string, to: ?string, delay: ?int, length: ?int, start: ?string, end: ?string, latitude: float, longitude: float, along: int}>
     */
    public function along(Route $route, CarbonImmutable $departure, CarbonImmutable $arrival, string $locale, bool $fresh = false): array
    {
        if (ApiUsage::tomTomSaving()) {
            $this->skipped = true;

            return [];
        }

        $corridor = new Corridor($route);
        $soon = $departure->lte(now()->addHours(2));
        $near = $departure->lte(now()->addDays(self::OPEN_ENDED_DAYS));
        $incidents = [];

        foreach ($this->boxes($route) as $box) {
            foreach ($this->fetch($box, $locale, $fresh) as $incident) {
                $properties = $incident['properties'] ?? [];
                $category = self::CATEGORIES[$properties['iconCategory'] ?? 0] ?? null;
                $coordinates = $this->coordinates($incident['geometry'] ?? []);

                if ($category === null || ! $coordinates || isset($incidents[$properties['id'] ?? ''])) {
                    continue;
                }

                if (in_array($category, self::CURRENT, true) && ! $soon) {
                    continue;
                }

                // Not during the trip.
                $start = isset($properties['startTime']) ? CarbonImmutable::parse($properties['startTime']) : null;
                $end = isset($properties['endTime']) ? CarbonImmutable::parse($properties['endTime']) : null;

                if (($start && $start->gt($arrival)) || ($end && $end->lt($departure))) {
                    continue;
                }

                // Going on now without a known end: for a trip far ahead, maybe over by then.
                if ($end === null && ! $near && ($start === null || $start->lte(now()))) {
                    continue;
                }

                // On the route, in the direction it is driven.
                $first = $corridor->locatePoint($coordinates[0][1], $coordinates[0][0], self::ON_ROUTE);
                $last = $corridor->locatePoint(end($coordinates)[1], end($coordinates)[0], self::ON_ROUTE);

                if ($first === null || ($last !== null && count($coordinates) > 1 && $last['along'] < $first['along'])) {
                    continue;
                }

                $incidents[$properties['id']] = [
                    'category' => $category,
                    'description' => (string) ($properties['events'][0]['description'] ?? ''),
                    'road' => $road = $properties['roadNumbers'][0] ?? null,
                    // The road number as on the signs of the country it is in.
                    'road_badge' => $road === null ? null : Roads::badge(CountryCodes::iso2((string) $route->countryAt($first['index'])), (string) $road),
                    'from' => $properties['from'] ?? null,
                    'to' => $properties['to'] ?? null,
                    'delay' => isset($properties['delay']) ? (int) $properties['delay'] : null,
                    'length' => isset($properties['length']) ? (int) round((float) $properties['length']) : null,
                    'start' => $start?->toIso8601String(),
                    'end' => $end?->toIso8601String(),
                    // The stretch of road, for road works and closures to be drawn as a line.
                    'line' => in_array($category, self::LINES, true) ? $this->line($coordinates) : null,
                    'latitude' => (float) $coordinates[0][1],
                    'longitude' => (float) $coordinates[0][0],
                    'along' => (int) round($first['along']),
                ];
            }
        }

        $incidents = array_values($incidents);
        usort($incidents, fn (array $a, array $b) => $a['along'] <=> $b['along']);

        return $incidents;
    }

    /**
     * Boxes that together cover the route: west, south, east, north.
     *
     * @return list<array{float, float, float, float}>
     */
    private function boxes(Route $route): array
    {
        $boxes = [];
        $box = null;

        foreach ($route->points as [$lat, $lon]) {
            $grown = $box === null ? [$lon, $lat, $lon, $lat] : [min($box[0], $lon), min($box[1], $lat), max($box[2], $lon), max($box[3], $lat)];

            if ($box !== null && ($grown[2] - $grown[0] > self::BOX || $grown[3] - $grown[1] > self::BOX)) {
                $boxes[] = $box;
                $grown = [$lon, $lat, $lon, $lat];
            }

            $box = $grown;
        }

        if ($box !== null) {
            $boxes[] = $box;
        }

        // A little room around the route, rounded out.
        $out = fn (float $value, bool $up) => round(($up ? ceil(($value + 0.01) / self::ROUND_BOX) : floor(($value - 0.01) / self::ROUND_BOX)) * self::ROUND_BOX, 1);

        return array_map(fn (array $box) => [$out($box[0], false), $out($box[1], false), $out($box[2], true), $out($box[3], true)], $boxes);
    }

    /**
     * The incidents in a box, kept for a while.
     *
     * @param  array{float, float, float, float}  $box
     * @return list<array<string, mixed>>
     */
    private function fetch(array $box, string $locale, bool $fresh = false): array
    {
        $key = 'trip-planner:traffic:'.$locale.':'.implode(',', $box);

        if (! $fresh && is_array($kept = Cache::get($key))) {
            return $kept;
        }

        $incidents = $this->request($box, $locale);

        if ($incidents !== null) {
            Cache::put($key, $incidents, now()->addMinutes((int) config('trip_planner.tomtom.traffic_cache_minutes')));
        }

        return $incidents ?? [];
    }

    /**
     * @param  array{float, float, float, float}  $box
     * @return list<array<string, mixed>>|null Null when TomTom did not answer.
     */
    private function request(array $box, string $locale): ?array
    {
        try {
            $response = Http::timeout(15)->get(config('trip_planner.tomtom.base_url').'traffic/services/5/incidentDetails', [
                'key' => config('trip_planner.tomtom.key'),
                'bbox' => implode(',', array_map(fn (float $value) => round($value, 5), $box)),
                'fields' => '{incidents{type,geometry{type,coordinates},properties{id,iconCategory,events{description},startTime,endTime,from,to,length,delay,roadNumbers}}}',
                'language' => config("trip_planner.tomtom.language.{$locale}", 'en-GB'),
                'timeValidityFilter' => 'present,future',
            ]);
        } catch (Throwable $exception) {
            Log::warning('TomTom traffic call failed.', ['message' => $exception->getMessage()]);

            return null;
        }

        return $response->ok() ? array_values((array) ($response->json('incidents') ?? [])) : null;
    }

    /**
     * The coordinates of a geometry, as longitude and latitude.
     *
     * @param  array<string, mixed>  $geometry
     * @return list<array{float, float}>
     */
    /**
     * The coordinates of a stretch of road, at most MAX_LINE_POINTS of them,
     * rounded to about a meter.
     *
     * @param  list<array{0: float, 1: float}>  $coordinates
     * @return list<array{float, float}>|null
     */
    private function line(array $coordinates): ?array
    {
        if (count($coordinates) < 2) {
            return null;
        }

        $step = (int) ceil(count($coordinates) / self::MAX_LINE_POINTS);
        $points = array_values(array_filter($coordinates, fn ($point, int $index) => $index % $step === 0, ARRAY_FILTER_USE_BOTH));

        if (end($points) !== end($coordinates)) {
            $points[] = end($coordinates);
        }

        return array_map(fn (array $point) => [round((float) $point[0], 5), round((float) $point[1], 5)], $points);
    }

    private function coordinates(array $geometry): array
    {
        return match ($geometry['type'] ?? null) {
            'Point' => [$geometry['coordinates']],
            'LineString' => $geometry['coordinates'],
            default => [],
        };
    }
}
