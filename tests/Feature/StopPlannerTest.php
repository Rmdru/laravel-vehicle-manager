<?php

namespace Tests\Feature;

use App\Services\TripPlanner\Route;
use App\Services\TripPlanner\StopPlanner;
use App\Services\TripPlanner\VehicleRange;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class StopPlannerTest extends TestCase
{
    /**
     * A straight route of a distance, driven at a constant speed.
     */
    private function route(float $kilometers, float $speed = 100, string $departure = '08:00'): Route
    {
        $points = [];
        $distances = [];
        $times = [];

        for ($km = 0; $km <= $kilometers; $km++) {
            $points[] = [52 - $km / 111.32, 5.0];
            $distances[] = $km * 1000.0;
            $times[] = $km / $speed * 3600;
        }

        return new Route(
            points: $points,
            distances: $distances,
            times: $times,
            legEnds: [count($points) - 1],
            distance: $kilometers * 1000,
            driveSeconds: $kilometers / $speed * 3600,
            trafficDelay: 0,
            departure: CarbonImmutable::parse("2026-10-20 {$departure}", 'Europe/Amsterdam'),
            arrival: CarbonImmutable::parse("2026-10-20 {$departure}", 'Europe/Amsterdam')->addSeconds((int) ($kilometers / $speed * 3600)),
        );
    }

    /**
     * Places for a stop every so many kilometers, offering everything by default.
     *
     * @param  list<string>  $features
     * @return list<array<string, mixed>>
     */
    private function candidates(float $kilometers, float $every = 20, array $features = ['fuel', 'coffee', 'meal', 'toilet', 'parking']): array
    {
        $candidates = [];

        for ($km = $every; $km < $kilometers; $km += $every) {
            $candidates[] = [
                'poi_id' => (int) $km,
                'kind' => 'service_area',
                'name' => "Area {$km}",
                'brand' => null,
                'latitude' => 52 - $km / 111.32,
                'longitude' => 5.0,
                'features' => $features,
                'along' => $km * 1000.0,
                'offset' => 50,
                'country' => 'NLD',
            ];
        }

        return $candidates;
    }

    /**
     * The minutes of driving between the stops that meet a need, from the
     * departure to the arrival.
     *
     * @param  list<array<string, mixed>>  $stops
     * @return list<float>
     */
    private function gaps(array $stops, string $need, float $driveSeconds): array
    {
        $times = [0];

        foreach ($stops as $stop) {
            if (in_array($need, $stop['needs'], true)) {
                $times[] = $stop['drive_seconds'];
            }
        }

        $times[] = $driveSeconds;
        $gaps = [];

        for ($i = 1; $i < count($times); $i++) {
            $gaps[] = ($times[$i] - $times[$i - 1]) / 60;
        }

        return $gaps;
    }

    public function test_a_short_trip_has_no_stops()
    {
        $route = $this->route(150);

        $plan = (new StopPlanner($route, $this->candidates(150), [], new VehicleRange(50, 7, true)))->plan();

        $this->assertSame([], $plan['stops']);
        $this->assertSame([], $plan['warnings']);
    }

    public function test_breaks_come_every_105_to_180_minutes_of_driving()
    {
        $route = $this->route(900);

        $plan = (new StopPlanner($route, $this->candidates(900), [], null))->plan();

        foreach ($this->gaps($plan['stops'], 'break', $route->driveSeconds) as $n => $gap) {
            $this->assertLessThanOrEqual(180, $gap);

            // The last stretch to the destination may be shorter.
            if ($n < count($plan['stops'])) {
                $this->assertGreaterThanOrEqual(105, $gap);
            }
        }

        // As few stops as the rules allow: 9 hours of driving takes 2 breaks,
        // and lunch around 13:00 comes too soon after the first one.
        $this->assertCount(3, $plan['stops']);
    }

    /**
     * The clock time each stop is reached at.
     *
     * @param  list<array<string, mixed>>  $stops
     * @return list<string>
     */
    private function clocks(Route $route, array $stops): array
    {
        $before = 0;
        $clocks = [];

        foreach ($stops as $stop) {
            $clocks[] = $route->departure->addSeconds((int) $stop['drive_seconds'] + $before)->format('H:i');
            $before += $stop['minutes'] * 60;
        }

        return $clocks;
    }

    public function test_meals_are_planned_at_about_breakfast_lunch_and_dinner_time()
    {
        $route = $this->route(900, departure: '06:00');

        $plan = (new StopPlanner($route, $this->candidates(900), [], null))->plan();

        $clocks = $this->clocks($route, $plan['stops']);
        $meals = array_values(array_filter(
            array_map(fn (array $stop, string $clock) => in_array('meal', $stop['needs'], true) ? $clock : null, $plan['stops'], $clocks),
        ));

        // Breakfast around 8 and lunch around 13; the trip is over before dinner time.
        $this->assertCount(2, $meals);
        $this->assertTrue($meals[0] >= '07:00' && $meals[0] <= '09:00', "Breakfast at {$meals[0]}");
        $this->assertTrue($meals[1] >= '12:00' && $meals[1] <= '14:00', "Lunch at {$meals[1]}");
    }

    public function test_a_meal_just_after_leaving_is_eaten_before()
    {
        $route = $this->route(300, departure: '08:15');

        $plan = (new StopPlanner($route, $this->candidates(300), [], null))->plan();

        $this->assertEmpty(array_filter($plan['stops'], fn (array $stop) => in_array('meal', $stop['needs'], true)));
    }

    public function test_the_tank_never_runs_below_the_reserve()
    {
        $route = $this->route(900);
        $range = new VehicleRange(40, 8, true);

        $plan = (new StopPlanner($route, $this->candidates(900), [], $range, 0.5))->plan();

        $fuel = 40 * 0.5;
        $at = 0;

        foreach ([...$plan['stops'], ['along' => 900000.0, 'needs' => []]] as $stop) {
            $fuel -= $range->litersFor($stop['along'] - $at);
            $at = $stop['along'];
            $this->assertGreaterThanOrEqual(40 * 0.15 - 0.01, $fuel);

            if (in_array('fuel', $stop['needs'], true)) {
                $this->assertEqualsWithDelta(40 - $fuel, $stop['fuel_liters'], 0.1);
                $fuel = 40;
            }
        }
    }

    public function test_needs_are_met_at_the_same_stop()
    {
        // The break is due around lunch time.
        $route = $this->route(450, departure: '09:30');

        $plan = (new StopPlanner($route, $this->candidates(450), [], new VehicleRange(30, 7, true)))->plan();

        $this->assertCount(1, $plan['stops']);
        $this->assertSame(['break', 'meal', 'toilet', 'fuel'], $plan['stops'][0]['needs']);
        // A meal includes the break; refueling takes a little longer.
        $this->assertSame(55, $plan['stops'][0]['minutes']);
    }

    public function test_a_stop_soon_after_leaving_meets_only_what_is_needed()
    {
        // The tank is nearly empty: a stop to refuel within half an hour.
        $route = $this->route(300);

        $plan = (new StopPlanner($route, $this->candidates(300), [], new VehicleRange(20, 7, true), 0.3))->plan();

        // No break and no toilet yet, though the place offers both.
        $this->assertSame(['fuel'], $plan['stops'][0]['needs']);
        $this->assertLessThan(30 * 60, $plan['stops'][0]['drive_seconds']);
    }

    public function test_no_fuel_is_taken_when_the_destination_is_reached_before_the_tank_runs_low()
    {
        $route = $this->route(400);

        $plan = (new StopPlanner($route, $this->candidates(400), [], new VehicleRange(60, 6, true)))->plan();

        $this->assertNotContains('fuel', $plan['stops'][0]['needs']);
    }

    public function test_a_stay_along_the_way_at_meal_time_counts_as_a_break_and_a_meal()
    {
        // At Antwerp at 12:36.
        $route = $this->route(400, departure: '11:00');

        $plan = (new StopPlanner($route, $this->candidates(400), [['along' => 160000.0, 'stay' => 60, 'label' => 'Antwerp']], null))->plan();

        $this->assertSame('waypoint', $plan['stops'][0]['type']);
        $this->assertSame(['break', 'toilet', 'meal'], $plan['stops'][0]['needs']);
        $this->assertSame(60, $plan['stops'][0]['minutes']);
        // After the stay, the rest of the route is short enough to drive in one go.
        $this->assertCount(1, $plan['stops']);
    }

    public function test_a_rest_area_without_coffee_is_used_when_there_is_nothing_else()
    {
        // On the road at lunch time, until long after.
        $route = $this->route(600, departure: '10:00');

        $plan = (new StopPlanner($route, $this->candidates(600, 20, ['parking']), [], null))->plan();

        $this->assertSame(['break'], $plan['stops'][0]['needs']);
        $this->assertContains('no_coffee', $plan['warnings']);
        $this->assertContains('no_meal', $plan['warnings']);
    }

    public function test_fuel_is_taken_where_it_costs_the_least_with_the_detour()
    {
        $route = $this->route(450);
        $candidates = $this->candidates(450);
        // A gas station off the motorway at 100 km: cheaper, but a detour of 6 minutes.
        $candidates[] = [
            ...$candidates[0],
            'poi_id' => 9999,
            'kind' => 'gas_station',
            'features' => ['fuel'],
            'along' => 100000.0,
            'detour_meters' => 4000,
            'detour_seconds' => 360,
        ];
        usort($candidates, fn ($a, $b) => $a['along'] <=> $b['along']);

        $plan = fn (float $cheaper) => (new StopPlanner(
            $route,
            $candidates,
            [],
            new VehicleRange(40, 8, true),
            0.4,
            fn (array $candidate) => $candidate['poi_id'] === 9999 ? 2.00 - $cheaper : 2.00,
        ))->plan()['stops'];

        // 10 cents a liter less is worth the detour, and a stop of its own.
        $stops = $plan(0.10);
        $fuelStop = collect($stops)->first(fn (array $stop) => in_array('fuel', $stop['needs'], true));
        $this->assertSame(9999, $fuelStop['poi_id']);

        // 8 cents is not: a detour is only made for 10 cents or more.
        $stops = $plan(0.08);
        $fuelStop = collect($stops)->first(fn (array $stop) => in_array('fuel', $stop['needs'], true));
        $this->assertNotSame(9999, $fuelStop['poi_id']);
    }

    /**
     * Places for a stop every 20 km, and a hotel every 50 km.
     *
     * @return list<array<string, mixed>>
     */
    private function candidatesWithHotels(float $kilometers): array
    {
        $hotels = array_map(fn (array $candidate) => [
            ...$candidate,
            'poi_id' => $candidate['poi_id'] + 100000,
            'kind' => 'hotel',
            'features' => ['hotel', 'toilet'],
        ], $this->candidates($kilometers, 50));
        $candidates = [...$this->candidates($kilometers), ...$hotels];
        usort($candidates, fn ($a, $b) => $a['along'] <=> $b['along']);

        return $candidates;
    }

    public function test_the_travel_of_a_day_ends_at_a_hotel_within_10_hours()
    {
        $route = $this->route(1300);

        $plan = (new StopPlanner($route, $this->candidatesWithHotels(1300), [], null))->plan();

        $hotels = array_values(array_filter($plan['stops'], fn (array $stop) => in_array('hotel', $stop['needs'], true)));
        $this->assertCount(1, $hotels);

        // Driving and the stops before it, together.
        $before = array_filter($plan['stops'], fn (array $stop) => $stop['drive_seconds'] < $hotels[0]['drive_seconds']);
        $travel = $hotels[0]['drive_seconds'] + array_sum(array_column($before, 'minutes')) * 60;
        $this->assertLessThanOrEqual(600 * 60, $travel);
        $this->assertGreaterThan(510 * 60, $travel);
        $this->assertSame(600, $hotels[0]['minutes']);

        // After the night, the breaks start over.
        $times = [$hotels[0]['drive_seconds']];

        foreach ($plan['stops'] as $stop) {
            if ($stop['drive_seconds'] > $hotels[0]['drive_seconds'] && in_array('break', $stop['needs'], true)) {
                $times[] = $stop['drive_seconds'];
            }
        }

        $times[] = $route->driveSeconds;

        for ($i = 1; $i < count($times); $i++) {
            $this->assertLessThanOrEqual(180 * 60, $times[$i] - $times[$i - 1]);
        }
    }

    public function test_no_hotel_is_needed_for_a_trip_of_a_day()
    {
        $route = $this->route(700);

        $plan = (new StopPlanner($route, $this->candidatesWithHotels(700), [], null))->plan();

        $this->assertEmpty(array_filter($plan['stops'], fn (array $stop) => in_array('hotel', $stop['needs'], true)));
    }

    public function test_it_warns_when_there_is_no_hotel_in_time()
    {
        $route = $this->route(1300);

        $plan = (new StopPlanner($route, $this->candidates(1300), [], null))->plan();

        $this->assertContains('no_hotel', $plan['warnings']);
    }

    public function test_it_warns_when_there_is_no_place_to_stop()
    {
        $route = $this->route(900);

        $plan = (new StopPlanner($route, [], [], new VehicleRange(40, 8, true)))->plan();

        $this->assertSame([], $plan['stops']);
        $this->assertContains('no_break', $plan['warnings']);
        $this->assertContains('no_fuel', $plan['warnings']);
    }
}
