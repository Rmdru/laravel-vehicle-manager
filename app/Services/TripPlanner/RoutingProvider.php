<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use Carbon\CarbonInterface;

interface RoutingProvider
{
    /**
     * The fastest route by way of the waypoints and its alternatives, departing
     * or arriving at a time.
     *
     * @param  list<array{float, float}>  $waypoints  Latitude and longitude, from start to destination.
     * @return list<Route>
     *
     * @throws RoutingException
     */
    public function routes(array $waypoints, string $timeMode, CarbonInterface $time, int $alternatives): array;
}
