<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use Carbon\CarbonImmutable;

/**
 * A route as the routing provider planned it: the points along it, with the
 * distance and the driving time from the start to each of them.
 */
class Route
{
    /**
     * Where the route is busier than without traffic at the time it is driven,
     * as the routing provider usually sees it then: from a point to a point,
     * with the time lost there in seconds.
     *
     * @var list<array{start: int, end: int, level: string, delay: int}>
     */
    public array $congestion = [];

    /**
     * @param  list<array{float, float}>  $points  Latitude and longitude.
     * @param  list<float>  $distances  The distance from the start to each point, in meters.
     * @param  list<float>  $times  The driving time from the start to each point, in seconds.
     * @param  list<int>  $legEnds  The index of the point each leg ends at.
     * @param  list<array{country: string, start: int, end: int}>  $countries  ISO 3 codes.
     * @param  list<array{start: int, end: int}>  $tollRoads
     * @param  list<array{country: ?string, start: int, end: int}>  $vignettes
     * @param  list<array{start: int, end: int}>  $motorways
     * @param  list<array{ref: string, country: ?string}>  $roads  The main roads, such as A2 or E411, in the order they are driven.
     * @param  list<array{start: int, ref: ?string, country: ?string}>  $roadRuns  The road driven on from a point on, if it has a number.
     */
    public function __construct(
        public array $points,
        public array $distances,
        public array $times,
        public array $legEnds,
        public float $distance,
        public float $driveSeconds,
        public float $trafficDelay,
        public CarbonImmutable $departure,
        public CarbonImmutable $arrival,
        public array $countries = [],
        public array $tollRoads = [],
        public array $vignettes = [],
        public array $motorways = [],
        public array $roads = [],
        public array $roadRuns = [],
    ) {}

    /**
     * The numbered road the point at an index lies on, if any.
     *
     * @return array{ref: string, country: ?string}|null
     */
    public function roadAt(int $index): ?array
    {
        $road = null;

        foreach ($this->roadRuns as $run) {
            if ($run['start'] > $index) {
                break;
            }

            $road = $run['ref'] !== null ? ['ref' => $run['ref'], 'country' => $run['country']] : null;
        }

        return $road;
    }

    /**
     * Whether the point at an index lies on a motorway.
     */
    public function onMotorway(int $index): bool
    {
        foreach ($this->motorways as $section) {
            if ($index >= $section['start'] && $index <= $section['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The country the point at an index lies in.
     */
    public function countryAt(int $index): ?string
    {
        foreach ($this->countries as $section) {
            if ($index >= $section['start'] && $index <= $section['end']) {
                return $section['country'];
            }
        }

        return null;
    }

    /**
     * The driving time from the start at a distance along the route.
     */
    public function timeAt(float $distance): float
    {
        $index = $this->indexAt($distance);

        if ($index >= count($this->points) - 1) {
            return $this->driveSeconds;
        }

        $span = $this->distances[$index + 1] - $this->distances[$index];
        $part = $span > 0 ? ($distance - $this->distances[$index]) / $span : 0;

        return $this->times[$index] + $part * ($this->times[$index + 1] - $this->times[$index]);
    }

    /**
     * The distance from the start after a driving time.
     */
    public function distanceAfter(float $seconds): float
    {
        $low = 0;
        $high = count($this->times) - 1;

        if ($seconds >= $this->times[$high]) {
            return $this->distance;
        }

        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);
            $this->times[$middle] <= $seconds ? $low = $middle : $high = $middle;
        }

        $span = $this->times[$high] - $this->times[$low];
        $part = $span > 0 ? ($seconds - $this->times[$low]) / $span : 0;

        return $this->distances[$low] + $part * ($this->distances[$high] - $this->distances[$low]);
    }

    /**
     * The index of the last point at or before a distance along the route.
     */
    public function indexAt(float $distance): int
    {
        $low = 0;
        $high = count($this->distances) - 1;

        if ($distance >= $this->distances[$high]) {
            return $high;
        }

        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);
            $this->distances[$middle] <= $distance ? $low = $middle : $high = $middle;
        }

        return $low;
    }
}
