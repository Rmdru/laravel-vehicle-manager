<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TripPlanner\PoiRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches the places of an area of the station map that is not in the cache
 * yet, such as outside the countries that are fetched every night.
 */
class FetchPoiTiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  list<string>  $tiles
     */
    public function __construct(public array $tiles) {}

    public function handle(PoiRepository $pois): void
    {
        // All of them, in as many blocks as they lie in.
        $pois->warm($this->tiles, PHP_INT_MAX);
    }
}
