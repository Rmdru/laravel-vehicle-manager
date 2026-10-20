<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TripPlanner\Geo;
use App\Services\TripPlanner\PoiRepository;
use Illuminate\Console\Command;

class WarmTripPlannerCache extends Command
{
    protected $signature = 'trip-planner:warm
        {--blocks= : At most this many blocks of tiles, instead of the nightly number}';

    protected $description = 'Fetch the places along the roads of the countries the trip planner covers ahead of time';

    public function handle(PoiRepository $pois): int
    {
        $tiles = $this->tiles();
        $blocks = (int) ($this->option('blocks') ?: config('trip_planner.warm.blocks'));

        $fetched = $pois->warm($tiles, $blocks);

        $this->info("Fetched {$fetched} of ".count($tiles).' tiles.');

        if ($pois->failed) {
            $this->warn(count($pois->failed).' tiles could not be fetched.');
        }

        return self::SUCCESS;
    }

    /**
     * The tiles of the countries the trip planner covers ahead of time.
     *
     * @return list<string>
     */
    private function tiles(): array
    {
        $size = (float) config('trip_planner.tile_size');
        $tiles = [];

        foreach (config('trip_planner.warm.countries') as [$south, $west, $north, $east]) {
            for ($lat = $south; $lat < $north; $lat += $size) {
                for ($lon = $west; $lon < $east; $lon += $size) {
                    $tiles[Geo::tile($lat, $lon)] = true;
                }
            }
        }

        return array_keys($tiles);
    }
}
