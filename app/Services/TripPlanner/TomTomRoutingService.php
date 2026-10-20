<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class TomTomRoutingService implements RoutingProvider
{
    /** A stretch this much slower than without traffic is busy, or has a jam. */
    private const BUSY = 1.2;

    private const JAM = 1.6;

    /** The requests TomTom takes per second in its free tier, with some room to spare. */
    private const PER_SECOND = 3;

    /** The least time lost on a stretch to call it busy, in seconds: short stretches in towns vary a lot. */
    private const MIN_DELAY = 60;

    /** The most supporting points sent to have the route in the night follow the same roads. */
    private const SUPPORTING_POINTS = 1500;

    /** The hour of the night the roads are taken to be free, and the driving time each part planned then takes, in seconds. */
    private const NIGHT = 2;

    private const PART_SECONDS = 3 * 3600;

    /** How close the instructions of the route and of the route in the night lie to be the same, in meters. */
    private const MATCH_METERS = 20;

    public function routes(array $waypoints, string $timeMode, CarbonInterface $time, int $alternatives): array
    {
        $locations = implode(':', array_map(fn (array $point) => $point[0].','.$point[1], $waypoints));

        // Planning for now takes the current traffic into account, later on the traffic as it usually is.
        $time = $time->isPast() ? null : $time->format('Y-m-d\TH:i:sP');

        $query = http_build_query(array_filter([
            'key' => config('trip_planner.tomtom.key'),
            'travelMode' => 'car',
            'routeType' => 'fastest',
            'traffic' => 'true',
            'maxAlternatives' => $alternatives,
            'instructionsType' => 'coded',
            'departAt' => $timeMode === 'departure' ? ($time ?? 'now') : null,
            'arriveAt' => $timeMode === 'arrival' ? $time : null,
        ], fn ($value) => $value !== null));

        // The section types are repeated rather than sent as an array.
        foreach (['country', 'tollRoad', 'tollVignette', 'motorway'] as $sectionType) {
            $query .= '&sectionType='.$sectionType;
        }

        try {
            $response = Http::timeout(30)
                ->retry(2, 500, throw: false)
                ->get(config('trip_planner.tomtom.base_url')."routing/1/calculateRoute/{$locations}/json?{$query}");
        } catch (Throwable $exception) {
            Log::warning('TomTom routing call failed.', ['message' => $exception->getMessage()]);

            throw new RoutingException(__('The route could not be planned. Please try again later.'), previous: $exception);
        }

        if (! $response->ok()) {
            Log::warning('TomTom routing HTTP response not OK', ['status' => $response->status(), 'body' => $response->json('detailedError.message')]);

            throw new RoutingException($response->status() === 400
                ? __('There is no route between these places.')
                : __('The route could not be planned. Please try again later.'));
        }

        $data = $response->json('routes') ?? [];
        $routes = array_map(fn (array $route) => $this->route($route), $data);
        $this->congestion($routes, $data);

        return $routes;
    }

    /**
     * Where each route is busy. The route is planned once more along the same
     * roads, departing in the night, when they are free: the driving time of
     * each stretch between two instructions is compared with it. The traffic
     * of a route for later on is the traffic as it usually is then. A long
     * route is planned again in parts, as a night only lasts a few hours.
     * Without an answer the routes simply show no congestion.
     *
     * @param  list<Route>  $routes
     * @param  list<array<string, mixed>>  $data
     */
    private function congestion(array $routes, array $data): void
    {
        $night = CarbonImmutable::now(config('trip_planner.timezone'))->addDay()->setTime(self::NIGHT, 0)->format('Y-m-d\TH:i:sP');
        $query = http_build_query([
            'key' => config('trip_planner.tomtom.key'),
            'travelMode' => 'car',
            'routeType' => 'fastest',
            'traffic' => 'true',
            'departAt' => $night,
            'instructionsType' => 'coded',
        ]);
        $requests = [];

        foreach ($routes as $n => $route) {
            foreach ($this->parts($data[$n]['guidance']['instructions'] ?? [], count($route->points) - 1) as [$from, $to]) {
                $requests[] = ['route' => $n, 'from' => $from, 'to' => $to];
            }
        }

        try {
            $responses = $this->batched($requests, fn (Pool $pool, array $request) => $this->inTheNight($pool, $routes[$request['route']], $request, $query));
        } catch (Throwable $exception) {
            Log::warning('TomTom routing in the night failed.', ['message' => $exception->getMessage()]);

            return;
        }

        foreach ($requests as $r => $request) {
            $route = $routes[$request['route']];
            $response = $responses[$r] ?? null;
            $free = $response instanceof Response && $response->ok() ? $response->json('routes.0') : null;
            $length = $route->distances[$request['to']] - $route->distances[$request['from']];

            // Only when it followed the same roads.
            if (! is_array($free) || abs((float) $free['summary']['lengthInMeters'] - $length) > max(300, $length * 0.005)) {
                continue;
            }

            $route->congestion = [...$route->congestion, ...$this->stretches(
                $data[$request['route']]['guidance']['instructions'] ?? [],
                $free['guidance']['instructions'] ?? [],
                $request['from'],
                $request['to'],
                $route->distances[$request['from']],
                $route->times[$request['from']],
            )];
        }
    }

    /**
     * Requests in batches that stay within the requests TomTom takes per second.
     *
     * @param  list<array{route: int, from: int, to: int}>  $requests
     * @param  Closure(Pool, array{route: int, from: int, to: int}): mixed  $send
     * @return list<mixed> The responses, in the order of the requests.
     */
    private function batched(array $requests, Closure $send): array
    {
        $responses = [];
        $batches = array_chunk($requests, self::PER_SECOND);

        foreach ($batches as $n => $batch) {
            $started = microtime(true);
            // The pool answers in the order the responses came in.
            $answers = Http::pool(fn (Pool $pool) => array_map(fn (array $request) => $send($pool, $request), $batch));
            ksort($answers);
            $responses = [...$responses, ...array_values($answers)];
            $left = 1.0 - (microtime(true) - $started);

            if ($n < count($batches) - 1 && $left > 0) {
                Sleep::usleep((int) ($left * 1_000_000));
            }
        }

        return $responses;
    }

    /**
     * A part of a route planned again in the night, along the same roads.
     *
     * @param  array{route: int, from: int, to: int}  $request
     */
    private function inTheNight(Pool $pool, Route $route, array $request, string $query): mixed
    {
        $points = array_slice($route->points, $request['from'], $request['to'] - $request['from'] + 1);
        $locations = implode(',', $points[0]).':'.implode(',', end($points));
        $step = (int) max(1, ceil(count($points) / self::SUPPORTING_POINTS));
        $supporting = [];

        foreach ($points as $index => $point) {
            if ($index % $step === 0) {
                $supporting[] = ['latitude' => $point[0], 'longitude' => $point[1]];
            }
        }

        return $pool->timeout(30)
            // Too many requests in a second: once more a second later.
            ->retry(3, 1100, fn (Throwable $exception) => $exception instanceof RequestException && $exception->response->status() === 429, throw: false)
            ->post(config('trip_planner.tomtom.base_url')."routing/1/calculateRoute/{$locations}/json?{$query}", ['supportingPoints' => $supporting]);
    }

    /**
     * The parts of a route planned again in the night: from an instruction to
     * an instruction, a few hours of driving each.
     *
     * @param  list<array<string, mixed>>  $instructions
     * @return list<array{int, int}> The first and the last point of each part.
     */
    private function parts(array $instructions, int $last): array
    {
        $parts = [];
        $from = 0;
        $since = 0.0;

        foreach ($instructions as $instruction) {
            $index = (int) $instruction['pointIndex'];

            if ($index < $last && $index > $from && (float) $instruction['travelTimeInSeconds'] - $since >= self::PART_SECONDS) {
                $parts[] = [$from, $index];
                $from = $index;
                $since = (float) $instruction['travelTimeInSeconds'];
            }
        }

        return $last > $from ? [...$parts, [$from, $last]] : $parts;
    }

    /**
     * The busy stretches of a part of a route between two instructions, from
     * the driving times with traffic and in the night.
     *
     * @param  list<array<string, mixed>>  $traffic  The instructions of the whole route.
     * @param  list<array<string, mixed>>  $free  The instructions of the part, planned in the night.
     * @return list<array{start: int, end: int, level: string, delay: int}>
     */
    private function stretches(array $traffic, array $free, int $from, int $to, float $offset, float $time): array
    {
        $freeTimes = [];

        foreach ($free as $instruction) {
            $freeTimes[(int) round((float) $instruction['routeOffsetInMeters'] / self::MATCH_METERS)] = (float) $instruction['travelTimeInSeconds'];
        }

        $stretches = [];
        $previous = ['index' => $from, 'traffic' => $time, 'free' => 0.0];

        foreach ($traffic as $instruction) {
            $index = (int) $instruction['pointIndex'];

            if ($index <= $from || $index > $to) {
                continue;
            }

            $key = (int) round(((float) $instruction['routeOffsetInMeters'] - $offset) / self::MATCH_METERS);
            $freeTime = $freeTimes[$key] ?? $freeTimes[$key - 1] ?? $freeTimes[$key + 1] ?? null;

            if ($freeTime === null) {
                continue;
            }

            $withTraffic = (float) $instruction['travelTimeInSeconds'] - $previous['traffic'];
            $withoutTraffic = $freeTime - $previous['free'];
            $delay = $withTraffic - $withoutTraffic;

            if ($withoutTraffic > 0 && $delay >= self::MIN_DELAY && $withTraffic / $withoutTraffic >= self::BUSY) {
                $stretches[] = [
                    'start' => $previous['index'],
                    'end' => $index,
                    'level' => $withTraffic / $withoutTraffic >= self::JAM ? 'jam' : 'busy',
                    'delay' => (int) round($delay),
                ];
            }

            $previous = ['index' => $index, 'traffic' => (float) $instruction['travelTimeInSeconds'], 'free' => $freeTime];
        }

        return $stretches;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function route(array $data): Route
    {
        $points = [];
        $legEnds = [];

        foreach ($data['legs'] as $leg) {
            foreach ($leg['points'] as $point) {
                $points[] = [(float) $point['latitude'], (float) $point['longitude']];
            }

            $legEnds[] = count($points) - 1;
        }

        [$distances, $times] = $this->timeline($points, $data['guidance']['instructions'] ?? [], $data['summary']);

        $sections = collect($data['sections'] ?? []);
        $span = fn (array $section) => ['start' => (int) $section['startPointIndex'], 'end' => (int) $section['endPointIndex']];
        $vignettes = $sections->where('sectionType', 'TOLL_VIGNETTE')
            ->map(fn (array $section) => ['country' => $section['countryCode'] ?? null, ...$span($section)])->values()->all();
        $vignetteSpans = array_map(fn (array $section) => [$section['start'], $section['end']], $vignettes);

        return new Route(
            points: $points,
            distances: $distances,
            times: $times,
            legEnds: $legEnds,
            distance: (float) $data['summary']['lengthInMeters'],
            driveSeconds: (float) $data['summary']['travelTimeInSeconds'],
            trafficDelay: (float) ($data['summary']['trafficDelayInSeconds'] ?? 0),
            departure: CarbonImmutable::parse($data['summary']['departureTime']),
            arrival: CarbonImmutable::parse($data['summary']['arrivalTime']),
            countries: $sections->where('sectionType', 'COUNTRY')
                ->map(fn (array $section) => ['country' => $section['countryCode'], ...$span($section)])->values()->all(),
            // A road that is paid for with a vignette is not a toll road of its own.
            tollRoads: $sections->where('sectionType', 'TOLL_ROAD')->map($span)
                ->reject(fn (array $section) => in_array([$section['start'], $section['end']], $vignetteSpans, true))->values()->all(),
            vignettes: $vignettes,
            motorways: $sections->where('sectionType', 'MOTORWAY')->map($span)->values()->all(),
            roads: $this->mainRoads($data['guidance']['instructions'] ?? []),
            roadRuns: array_map(fn (array $instruction) => [
                'start' => (int) $instruction['pointIndex'],
                'ref' => isset($instruction['roadNumbers'][0]) ? (string) $instruction['roadNumbers'][0] : null,
                'country' => $instruction['countryCode'] ?? null,
            ], $data['guidance']['instructions'] ?? []),
        );
    }

    /**
     * The roads most of the route is driven on, in the order they are driven.
     *
     * @param  list<array<string, mixed>>  $instructions
     * @return list<array{ref: string, country: ?string}>
     */
    private function mainRoads(array $instructions): array
    {
        $roads = [];
        $countries = [];

        foreach ($instructions as $n => $instruction) {
            $road = $instruction['roadNumbers'][0] ?? null;
            $next = $instructions[$n + 1]['routeOffsetInMeters'] ?? null;

            if ($road !== null && $next !== null) {
                $roads[$road] = ($roads[$road] ?? 0) + $next - $instruction['routeOffsetInMeters'];
                $countries[$road] ??= $instruction['countryCode'] ?? null;
            }
        }

        $driven = array_keys($roads);
        arsort($roads);
        $main = array_slice(array_keys(array_filter($roads, fn ($meters) => $meters >= 20000)), 0, 3);

        return array_values(array_map(
            // Road numbers such as "1" became integer keys.
            fn ($road) => ['ref' => (string) $road, 'country' => $countries[$road]],
            array_filter($driven, fn ($road) => in_array($road, $main, true)),
        ));
    }

    /**
     * The distance and the driving time from the start to each point. The
     * instructions tell both at the points they are given at; in between the
     * distance is measured, and the time divided by distance.
     *
     * @param  list<array{float, float}>  $points
     * @param  list<array<string, mixed>>  $instructions
     * @param  array<string, mixed>  $summary
     * @return array{list<float>, list<float>}
     */
    private function timeline(array $points, array $instructions, array $summary): array
    {
        $measured = [0.0];

        for ($i = 1, $count = count($points); $i < $count; $i++) {
            $measured[$i] = $measured[$i - 1] + Geo::distance($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }

        $anchors = [0 => [0.0, 0.0]];

        foreach ($instructions as $instruction) {
            $anchors[(int) $instruction['pointIndex']] = [(float) $instruction['routeOffsetInMeters'], (float) $instruction['travelTimeInSeconds']];
        }

        $last = count($points) - 1;
        $anchors[$last] = [(float) $summary['lengthInMeters'], (float) $summary['travelTimeInSeconds']];
        ksort($anchors);

        $distances = [];
        $times = [];
        $indexes = array_keys($anchors);

        foreach ($indexes as $n => $from) {
            $to = $indexes[$n + 1] ?? null;

            if ($to === null) {
                $distances[$from] = $anchors[$from][0];
                $times[$from] = $anchors[$from][1];

                break;
            }

            $span = $measured[$to] - $measured[$from];

            for ($i = $from; $i < $to; $i++) {
                $part = $span > 0 ? ($measured[$i] - $measured[$from]) / $span : 0;
                $distances[$i] = $anchors[$from][0] + $part * ($anchors[$to][0] - $anchors[$from][0]);
                $times[$i] = $anchors[$from][1] + $part * ($anchors[$to][1] - $anchors[$from][1]);
            }
        }

        return [array_values($distances), array_values($times)];
    }
}
