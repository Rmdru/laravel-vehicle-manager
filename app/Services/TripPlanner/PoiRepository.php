<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\Poi;
use App\Models\PoiTile;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PoiRepository
{
    /** @var list<string> The tiles that could not be fetched in the last call. */
    public array $failed = [];

    public function __construct(private OverpassService $overpass) {}

    /**
     * The places in the tiles along the points of routes, fetching the tiles
     * that were not fetched yet or too long ago.
     *
     * @param  list<array{float, float}>  $points
     * @param  (Closure(float, string, int, int): void)|null  $progress  Called with the part of the fetching that is done, the stage and its requests done and in all.
     * @param  float|null  $deadline  The moment (as microtime) the fetching stops: the rest is fetched another time.
     * @return Collection<int, Poi>
     */
    public function along(array $points, ?Closure $progress = null, ?float $deadline = null): Collection
    {
        $tiles = Geo::tilesAlong($points, (float) config('trip_planner.corridor.fetch'));

        $this->fetchGroups($this->groups(array_values(array_diff($tiles, $this->fresh($tiles, (int) config('trip_planner.tile_ttl_days'))))), $progress, $deadline);

        // Not the slow chargers: those are for the station map, not to stop at
        // on the way. Only the ones at a service area, as one of its facilities.
        $pois = Poi::whereIn('tile', $tiles)->where('kind', '!=', Poi::SLOW_CHARGER)->get();

        return $pois->concat($this->slowChargersAt($pois->where('kind', Poi::SERVICE_AREA), $tiles))->values();
    }

    /**
     * The slow chargers near service areas: their coordinates first, as a
     * tile in a city can have thousands of them.
     *
     * @param  Collection<int, Poi>  $areas
     * @param  list<string>  $tiles
     * @return Collection<int, Poi>
     */
    private function slowChargersAt(Collection $areas, array $tiles): Collection
    {
        if ($areas->isEmpty()) {
            return new Collection;
        }

        $radius = (float) config('trip_planner.service_area_radius') / 111320;
        $byCell = $areas->groupBy(fn (Poi $area) => round($area->latitude, 1).':'.round($area->longitude, 1));
        $near = Poi::query()
            ->whereIn('tile', $tiles)
            ->where('kind', Poi::SLOW_CHARGER)
            ->toBase()
            ->get(['id', 'latitude', 'longitude'])
            ->filter(function ($charger) use ($byCell, $radius) {
                foreach ([-0.1, 0, 0.1] as $dLat) {
                    foreach ([-0.1, 0, 0.1] as $dLon) {
                        foreach ($byCell->get(round($charger->latitude + $dLat, 1).':'.round($charger->longitude + $dLon, 1), []) as $area) {
                            if (abs($area->latitude - $charger->latitude) <= $radius && abs($area->longitude - $charger->longitude) <= $radius * 2) {
                                return true;
                            }
                        }
                    }
                }

                return false;
            })
            ->pluck('id');

        return $near->isEmpty() ? new Collection : Poi::whereIn('id', $near->all())->get();
    }

    /**
     * The tiles of a list that are not there yet, out of date, or of an older
     * version.
     *
     * @param  list<string>  $tiles
     * @return list<string>
     */
    public function missing(array $tiles): array
    {
        return array_values(array_diff($tiles, $this->fresh($tiles, (int) config('trip_planner.tile_ttl_days'))));
    }

    /**
     * Fetches the tiles that are missing, or will be out of date within some
     * days, of a list of tiles: at most some blocks of them.
     *
     * @param  list<string>  $tiles
     * @return int The number of tiles fetched.
     */
    public function warm(array $tiles, int $blocks): int
    {
        $days = (int) config('trip_planner.tile_ttl_days') - (int) config('trip_planner.warm.refresh_before_days');
        $stale = array_values(array_diff($tiles, $this->fresh($tiles, $days)));
        $groups = array_slice($this->groups($stale), 0, $blocks);

        $this->fetchGroups($groups);

        return count(array_merge(...$groups ?: [[]])) - count($this->failed);
    }

    /**
     * @param  list<list<string>>  $groups
     * @param  (Closure(float, string, int, int): void)|null  $progress
     */
    private function fetchGroups(array $groups, ?Closure $progress = null, ?float $deadline = null): void
    {
        $this->failed = array_merge(...$this->overpass->fetch($groups, $this->store(...), $progress, $deadline) ?: [[]]);
    }

    /**
     * The tiles of a list that were fetched within a number of days.
     *
     * @param  list<string>  $tiles
     * @return list<string>
     */
    private function fresh(array $tiles, int $days): array
    {
        return collect($tiles)
            ->chunk(1000)
            ->flatMap(fn ($chunk) => PoiTile::whereIn('tile', $chunk->all())
                ->where('fetched_at', '>', now()->subDays($days))
                // Fetched with what is fetched now.
                ->where('version', '>=', (int) config('trip_planner.tile_version'))
                ->pluck('tile'))
            ->all();
    }

    /**
     * The blocks of tiles the tiles lie in, each with all of its tiles: a block
     * is fetched in one request, and is then complete in the cache.
     *
     * @param  list<string>  $tiles
     * @return list<list<string>>
     */
    private function groups(array $tiles): array
    {
        $size = (int) config('trip_planner.overpass.block_size');
        $blocks = [];

        foreach ($tiles as $tile) {
            [$y, $x] = array_map('intval', explode(':', $tile));
            $blocks[(int) floor($y / $size).':'.(int) floor($x / $size)] = true;
        }

        return array_map(function (string $block) use ($size) {
            [$by, $bx] = array_map('intval', explode(':', $block));
            $group = [];

            for ($y = $by * $size; $y < ($by + 1) * $size; $y++) {
                for ($x = $bx * $size; $x < ($bx + 1) * $size; $x++) {
                    $group[] = "{$y}:{$x}";
                }
            }

            return $group;
        }, array_keys($blocks));
    }

    /**
     * Stores the places of tiles, replacing what was stored of them before.
     *
     * @param  list<string>  $tiles
     * @param  list<array<string, mixed>>  $elements
     */
    private function store(array $tiles, array $elements): void
    {
        $now = now();
        $rows = [];

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $kind = Poi::kindOf($tags);
            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lon = $element['lon'] ?? $element['center']['lon'] ?? null;

            if ($kind === null || $lat === null || $lon === null) {
                continue;
            }

            $rows[] = [
                'osm_type' => $element['type'][0],
                'osm_id' => $element['id'],
                'kind' => $kind,
                'name' => isset($tags['name']) ? mb_substr($tags['name'], 0, 255) : null,
                'brand' => isset($tags['brand']) ? mb_substr($tags['brand'], 0, 255) : null,
                'latitude' => round((float) $lat, 6),
                'longitude' => round((float) $lon, 6),
                'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
                'tile' => Geo::tile((float) $lat, (float) $lon),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($tiles, $rows, $now) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Poi::upsert($chunk, ['osm_type', 'osm_id'], ['kind', 'name', 'brand', 'latitude', 'longitude', 'tags', 'tile', 'updated_at']);
            }

            // What is no longer in OpenStreetMap.
            foreach (array_chunk($tiles, 500) as $chunk) {
                Poi::whereIn('tile', $chunk)->where('updated_at', '<', $now)->delete();
            }

            PoiTile::upsert(
                array_map(fn (string $tile) => ['tile' => $tile, 'fetched_at' => $now, 'version' => (int) config('trip_planner.tile_version')], $tiles),
                ['tile'],
                ['fetched_at', 'version'],
            );
        });
    }
}
