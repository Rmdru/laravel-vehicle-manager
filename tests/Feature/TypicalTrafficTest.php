<?php

namespace Tests\Feature;

use App\Services\TripPlanner\TomTomRoutingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class TypicalTrafficTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Sleep::fake();
    }

    /**
     * A route of 100 km, driven in the given seconds to the instruction halfway and to the end.
     *
     * @return array<string, mixed>
     */
    private function route(int $halfway, int $end): array
    {
        $points = [];

        for ($n = 0; $n <= 100; $n++) {
            $points[] = ['latitude' => round(52 - $n * 0.009, 4), 'longitude' => 5.0];
        }

        return [
            'summary' => ['lengthInMeters' => 100000, 'travelTimeInSeconds' => $end, 'trafficDelayInSeconds' => 0,
                'departureTime' => '2026-10-27T08:00:00+01:00', 'arrivalTime' => '2026-10-27T09:30:00+01:00'],
            'legs' => [['points' => $points]],
            'sections' => [],
            'guidance' => ['instructions' => [
                ['routeOffsetInMeters' => 0, 'travelTimeInSeconds' => 0, 'pointIndex' => 0, 'roadNumbers' => ['A4'], 'countryCode' => 'NLD'],
                ['routeOffsetInMeters' => 50000, 'travelTimeInSeconds' => $halfway, 'pointIndex' => 50, 'roadNumbers' => ['A4'], 'countryCode' => 'NLD'],
                ['routeOffsetInMeters' => 100000, 'travelTimeInSeconds' => $end, 'pointIndex' => 100],
            ]],
        ];
    }

    public function test_where_a_route_is_usually_busy_is_found_by_planning_it_again_in_the_night()
    {
        Http::fake(fn (Request $request) => Http::response(['routes' => [$request->method() === 'GET'
            // In the morning rush: the first half takes 50 minutes, the second 25.
            ? $this->route(3000, 4500)
            // In the night, along the same roads: 25 minutes each.
            : $this->route(1500, 3000)]]));

        $routes = app(TomTomRoutingService::class)->routes([[52.0, 5.0], [51.1, 5.0]], 'departure', CarbonImmutable::parse('2026-10-27 08:00'), 0);

        $this->assertSame([['start' => 0, 'end' => 50, 'level' => 'jam', 'delay' => 1500]], $routes[0]->congestion);
        // Along the same roads, departing in the night.
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'departAt=')
            && count($request['supportingPoints']) === 101);
    }

    public function test_a_route_in_the_night_along_other_roads_tells_nothing()
    {
        Http::fake(fn (Request $request) => Http::response(['routes' => [$request->method() === 'GET'
            ? $this->route(3000, 4500)
            : [...$this->route(1500, 3000), 'summary' => [...$this->route(1500, 3000)['summary'], 'lengthInMeters' => 112000]]]]));

        $routes = app(TomTomRoutingService::class)->routes([[52.0, 5.0], [51.1, 5.0]], 'departure', CarbonImmutable::parse('2026-10-27 08:00'), 0);

        $this->assertSame([], $routes[0]->congestion);
    }
}
