<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OverpassService
{
    /** Requests sent to a server at the same time: more get a client blocked for a while. */
    private const SLOTS_PER_SERVER = 1;

    /** The amenities of a service area lie within this many degrees of its center. */
    private const AREA_MARGIN = 0.003;

    /**
     * Fetches the places in blocks of tiles. First the service areas, rest
     * areas, gas stations, toll booths and motorway exits in each block, then
     * the restaurants, cafés and toilets at the service and rest areas found:
     * two light requests are answered much sooner than one heavy one. A
     * request waits in the queue of a server for half a minute or more, so the
     * requests are spread over the servers and sent at the same time; one that
     * fails is tried again at another server.
     *
     * @param  list<list<string>>  $blocks  The tiles of each block; together they form a rectangle.
     * @param  Closure(list<string>, list<array<string, mixed>>): void  $store  Called with the tiles and the elements of each block that is fetched.
     * @param  (Closure(float, string, int, int): void)|null  $progress  Called with the part that is done so far, the stage ("places" or "amenities") and the requests of it done and in all.
     * @param  float|null  $deadline  No request is sent after this moment (as microtime): what is fetched by then is stored.
     * @return list<list<string>> The blocks that could not be fetched.
     */
    public function fetch(array $blocks, Closure $store, ?Closure $progress = null, ?float $deadline = null): array
    {
        $places = $this->run(array_map(fn (array $tiles) => $this->placesQuery($tiles), $blocks), $progress, 0, 0.6, 'places', $deadline);

        $areaQueries = [];

        foreach ($places as $block => $elements) {
            $areas = array_filter($elements, fn (array $element) => in_array($element['tags']['highway'] ?? null, ['services', 'rest_area'], true));

            if ($areas) {
                $areaQueries[$block] = $this->amenitiesQuery($areas);
            }
        }

        $amenities = $this->run($areaQueries, $progress, 0.6, 1, 'amenities', $deadline);

        foreach ($places as $block => $elements) {
            // Without the amenities, the places themselves are still of use.
            $store($blocks[$block], [...$elements, ...($amenities[$block] ?? [])]);
        }

        return array_values(array_diff_key($blocks, $places));
    }

    /**
     * Runs queries spread over the servers, and the ones that fail again at
     * another server.
     *
     * @param  array<int, string>  $queries
     * @param  (Closure(float, string, int, int): void)|null  $progress  Called with the part that is done so far, from one part to another.
     * @return array<int, list<array<string, mixed>>> The elements, by the key of each query that succeeded.
     */
    private function run(array $queries, ?Closure $progress = null, float $from = 0, float $to = 1, string $stage = 'places', ?float $deadline = null): array
    {
        $servers = array_values(config('trip_planner.overpass.servers'));
        $tried = array_fill_keys(array_keys($queries), []);
        $pending = array_keys($queries);
        $results = [];

        $progress && $queries && $progress($from, $stage, 0, count($queries));

        while ($pending) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                Log::warning('Overpass took too long: the rest is fetched another time.', ['stage' => $stage, 'left' => count($pending), 'of' => count($queries)]);

                break;
            }

            $batch = [];
            $load = array_fill(0, count($servers), 0);

            foreach ($pending as $key) {
                $server = collect(array_keys($servers))
                    ->reject(fn (int $server) => in_array($server, $tried[$key], true) || $load[$server] >= self::SLOTS_PER_SERVER)
                    ->sortBy(fn (int $server) => $load[$server])
                    ->first();

                if ($server !== null) {
                    $batch[$key] = $server;
                    $load[$server]++;
                    $tried[$key][] = $server;
                }
            }

            if (! $batch) {
                break;
            }

            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (int $key, int $server) => $pool->as((string) $key)
                    ->timeout((int) config('trip_planner.overpass.timeout') + 30)
                    ->withUserAgent(config('app.name').' trip planner ('.config('app.url').')')
                    ->asForm()
                    ->post($servers[$server], ['data' => $queries[$key]]),
                array_keys($batch),
                $batch,
            ));

            foreach ($batch as $key => $server) {
                $response = $responses[(string) $key] ?? null;

                if ($response instanceof Response && $response->ok() && is_array($response->json('elements'))) {
                    $results[$key] = $response->json('elements');
                    $pending = array_values(array_diff($pending, [$key]));

                    continue;
                }

                Log::warning('Overpass request failed.', [
                    'server' => $servers[$server],
                    'status' => $response instanceof Response ? $response->status() : null,
                    'message' => $response instanceof Throwable ? $response->getMessage() : null,
                ]);

                if (count($tried[$key]) >= count($servers)) {
                    $pending = array_values(array_diff($pending, [$key]));
                }
            }

            if ($progress && $queries) {
                $done = count($queries) - count($pending);
                $progress($from + ($to - $from) * $done / count($queries), $stage, $done, count($queries));
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $tiles
     */
    private function placesQuery(array $tiles): string
    {
        $bounds = array_map(fn (string $tile) => Geo::tileBounds($tile), $tiles);
        $bbox = implode(',', [
            min(array_column($bounds, 0)),
            min(array_column($bounds, 1)),
            max(array_column($bounds, 2)),
            max(array_column($bounds, 3)),
        ]);

        // Exact values rather than a pattern: the server finds those in its index,
        // instead of going through every road.
        return $this->query($bbox, 'nwr["highway"="services"];'
            .'nwr["highway"="rest_area"];'
            .'nwr["amenity"="fuel"];'
            .'node["barrier"="toll_booth"];'
            .'node["highway"="motorway_junction"];'
            .'nwr["amenity"="charging_station"]["socket:type2_combo"];'
            .'nwr["tourism"="hotel"];'
            .'nwr["tourism"="motel"];');
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $areas
     */
    private function amenitiesQuery(array $areas): string
    {
        $statements = '';

        foreach ($areas as $area) {
            $lat = $area['lat'] ?? $area['center']['lat'];
            $lon = $area['lon'] ?? $area['center']['lon'];
            $bbox = implode(',', [
                $lat - self::AREA_MARGIN,
                $lon - self::AREA_MARGIN * 1.5,
                $lat + self::AREA_MARGIN,
                $lon + self::AREA_MARGIN * 1.5,
            ]);
            $statements .= "nwr[\"amenity\"~\"^(restaurant|cafe|fast_food|toilets|charging_station|car_wash|atm|shower|drinking_water|compressed_air)$\"]({$bbox});"
                ."nwr[\"shop\"]({$bbox});"
                ."nwr[\"leisure\"=\"playground\"]({$bbox});"
                ."nwr[\"tourism\"=\"picnic_site\"]({$bbox});";
        }

        return $this->query(null, $statements);
    }

    private function query(?string $bbox, string $statements): string
    {
        $timeout = (int) config('trip_planner.overpass.timeout');

        return "[out:json][timeout:{$timeout}]".($bbox ? "[bbox:{$bbox}]" : '').";({$statements});out center tags;";
    }
}
