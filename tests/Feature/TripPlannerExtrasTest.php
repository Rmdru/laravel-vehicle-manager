<?php

namespace Tests\Feature;

use App\Ai\Agents\TripAdvisor;
use App\Jobs\PlanTrip;
use App\Models\EnvironmentalSticker;
use App\Models\Poi;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Vignette;
use App\Services\TripPlanner\Prices\UkRetailers;
use App\Services\TripPlanner\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

/**
 * The notices, the traffic, the stops chosen by the driver, the travel tips,
 * electric vehicles and the prices of the United Kingdom.
 */
class TripPlannerExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-19 12:00:00');
        config(['trip_planner.tomtom.key' => 'test-key', 'ai.providers.gemini.key' => 'test-key']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    /**
     * A TomTom route straight south along longitude 5 at 120 km/h, through
     * Switzerland as far as the vignette goes.
     *
     * @return array<string, mixed>
     */
    private function route(): array
    {
        $points = [];

        for ($lat = 52.0; $lat >= 47.9999; $lat -= 0.01) {
            $points[] = ['latitude' => round($lat, 4), 'longitude' => 5.0];
        }

        $last = count($points) - 1;
        $seconds = (int) (445 / 120 * 3600);

        return [
            'summary' => ['lengthInMeters' => 445000, 'travelTimeInSeconds' => $seconds, 'trafficDelayInSeconds' => 0, 'departureTime' => '2026-12-02T08:00:00+01:00', 'arrivalTime' => '2026-12-02T11:42:30+01:00'],
            'legs' => [['points' => $points]],
            'sections' => [
                ['startPointIndex' => 0, 'endPointIndex' => 150, 'sectionType' => 'COUNTRY', 'countryCode' => 'NLD'],
                ['startPointIndex' => 150, 'endPointIndex' => 300, 'sectionType' => 'COUNTRY', 'countryCode' => 'DEU'],
                ['startPointIndex' => 300, 'endPointIndex' => $last, 'sectionType' => 'COUNTRY', 'countryCode' => 'CHE'],
                ['startPointIndex' => 0, 'endPointIndex' => $last, 'sectionType' => 'MOTORWAY'],
                ['startPointIndex' => 300, 'endPointIndex' => $last, 'sectionType' => 'TOLL_ROAD'],
                ['startPointIndex' => 300, 'endPointIndex' => $last, 'sectionType' => 'TOLL_VIGNETTE', 'countryCode' => 'CHE'],
            ],
            'guidance' => ['instructions' => [
                ['routeOffsetInMeters' => 0, 'travelTimeInSeconds' => 0, 'pointIndex' => 0, 'roadNumbers' => ['A2'], 'countryCode' => 'NLD'],
                ['routeOffsetInMeters' => 445000, 'travelTimeInSeconds' => $seconds, 'pointIndex' => $last],
            ]],
        ];
    }

    /**
     * Two service areas on the right side of the road, and a fast charger.
     *
     * @return list<array<string, mixed>>
     */
    private function places(): array
    {
        return [
            ['type' => 'way', 'id' => 1, 'center' => ['lat' => 50.20, 'lon' => 4.998], 'tags' => ['highway' => 'services', 'name' => 'Noord', 'toilets' => 'yes']],
            ['type' => 'node', 'id' => 2, 'lat' => 50.2005, 'lon' => 4.9975, 'tags' => ['amenity' => 'fuel', 'brand' => 'Shell']],
            ['type' => 'node', 'id' => 3, 'lat' => 50.1998, 'lon' => 4.9976, 'tags' => ['amenity' => 'charging_station', 'socket:type2_combo' => '4', 'socket:type2_combo:output' => '300 kW']],
            ['type' => 'way', 'id' => 4, 'center' => ['lat' => 49.90, 'lon' => 4.998], 'tags' => ['highway' => 'services', 'name' => 'Zuid', 'toilets' => 'yes']],
            ['type' => 'node', 'id' => 5, 'lat' => 49.9005, 'lon' => 4.9975, 'tags' => ['amenity' => 'fuel', 'brand' => 'Esso']],
        ];
    }

    /**
     * @param  array<string, mixed>  $stubs
     */
    private function fake(array $stubs = []): void
    {
        Http::fake([
            '*api.tomtom.com/routing/*' => Http::response(['routes' => [$this->route()]]),
            '*/api/interpreter' => Http::response(['elements' => $this->places()]),
            ...$stubs,
            '*' => Http::response([], 200),
        ]);
    }

    private function trip(Vehicle $vehicle, array $attributes = []): Trip
    {
        $trip = Trip::create([
            'user_id' => $vehicle->user_id,
            'vehicle_id' => $vehicle->id,
            'time_mode' => 'departure',
            'time_at' => '2026-12-02 07:00:00',
            'fuel_level' => 60,
            ...$attributes,
        ]);

        $trip->waypoints()->createMany([
            ['position' => 0, 'label' => 'Utrecht', 'latitude' => 52.0, 'longitude' => 5.0],
            ['position' => 1, 'label' => 'Bern', 'latitude' => 48.0, 'longitude' => 5.0],
        ]);

        return $trip;
    }

    private function plan(Trip $trip): array
    {
        (new PlanTrip($trip))->handle(app(TripPlanner::class));

        return $trip->refresh()->plan['routes'][0];
    }

    private function osmId(?int $id): ?int
    {
        return $id ? Poi::find($id)?->osm_id : null;
    }

    public function test_the_driver_is_warned_of_what_the_vehicle_needs_for_the_trip()
    {
        $this->fake();
        $vehicle = $this->signInWithVehicle(['rdw_data' => ['vervaldatum_apk_dt' => '2026-12-01']]);
        // A vignette of Switzerland that ends before the trip, and a sticker of Germany.
        Vignette::factory()->for($vehicle)->create(['country' => 'switzerland', 'start_date' => '2025-12-01', 'end_date' => '2026-01-31']);
        EnvironmentalSticker::factory()->for($vehicle)->create(['country' => 'germany', 'start_date' => '2020-01-01', 'end_date' => null]);

        $route = $this->plan($this->trip($vehicle));

        // The MOT expires the day before the trip.
        $this->assertSame(['apk_expires', 'vignette', 'winter_tires'], array_column($route['notices'], 'key'));
        $this->assertSame(['country' => 'CH'], $route['notices'][1]['params']);

        $this->get(route('trips.show', Trip::sole()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.plan.routes.0.notices.0', ['level' => 'warning', 'message' => 'The MOT (APK) expires on 1 December 2026, before the end of this trip.'])
                ->where('trip.plan.routes.0.notices.1.message', 'A vignette is needed in Switzerland: none is registered for the dates of this trip.'));
    }

    public function test_road_works_and_closures_on_the_route_are_shown()
    {
        $line = fn (float $from, float $to) => ['type' => 'LineString', 'coordinates' => [[5.0, $from], [5.0, $to]]];
        $this->fake(['*api.tomtom.com/traffic/*' => Http::response(['incidents' => [
            // On the route, in the direction it is driven.
            ['type' => 'Feature', 'geometry' => $line(50.5, 50.4), 'properties' => ['id' => 'a', 'iconCategory' => 9, 'events' => [['description' => 'Werkzaamheden']], 'startTime' => '2026-11-01T00:00:00Z', 'endTime' => '2026-12-31T00:00:00Z', 'roadNumbers' => ['E25']]],
            // The other direction.
            ['type' => 'Feature', 'geometry' => $line(50.4, 50.5), 'properties' => ['id' => 'b', 'iconCategory' => 8, 'events' => [['description' => 'Afgesloten']]]],
            // Over before the trip.
            ['type' => 'Feature', 'geometry' => $line(50.0, 49.9), 'properties' => ['id' => 'c', 'iconCategory' => 8, 'endTime' => '2026-11-01T00:00:00Z']],
            // Going on now without a known end: maybe over by December.
            ['type' => 'Feature', 'geometry' => $line(50.3, 50.2), 'properties' => ['id' => 'f', 'iconCategory' => 7, 'startTime' => '2026-01-01T00:00:00Z']],
            // A jam now: of no interest for a trip in December.
            ['type' => 'Feature', 'geometry' => $line(49.8, 49.7), 'properties' => ['id' => 'd', 'iconCategory' => 6, 'delay' => 600]],
            // Elsewhere.
            ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [6.0, 50.5]], 'properties' => ['id' => 'e', 'iconCategory' => 8]],
        ]])]);

        $route = $this->plan($this->trip($this->signInWithVehicle()));

        $this->assertCount(1, $route['traffic']);
        $this->assertSame('road_works', $route['traffic'][0]['category']);
        $this->assertSame('E25', $route['traffic'][0]['road']);
        // As on the road signs: European roads are green everywhere.
        $this->assertSame(['label' => 'E25', 'classes' => 'bg-green-600 text-white'], $route['traffic'][0]['road_badge']);
        // Road works are drawn along the stretch of road.
        $this->assertCount(2, $route['traffic'][0]['line']);
        $this->assertEqualsWithDelta(166000, $route['traffic'][0]['along'], 2000);
    }

    public function test_the_driver_chooses_where_to_stop()
    {
        Queue::fake();
        $this->fake();
        $vehicle = $this->signInWithVehicle(['tank_capacity' => 50]);
        $trip = $this->trip($vehicle);
        $route = $this->plan($trip);
        $planned = $route['stops'][0]['poi_id'];
        $other = collect($route['candidates'])->pluck('id')->first(fn (int $id) => $id !== $planned);

        // Not at the place the planner chose: the trip is planned again without it.
        $this->patch(route('trips.stops', $trip), ['action' => 'exclude', 'poi' => $planned])->assertRedirect();
        $this->assertSame([$planned], $trip->refresh()->excluded_stops);
        $this->assertSame(Trip::STATUS_PLANNING, $trip->status);
        Queue::assertPushed(PlanTrip::class);

        $this->assertNotContains($planned, array_column($this->plan($trip)['stops'], 'poi_id'));

        // At another place of the driver's choice.
        $this->patch(route('trips.stops', $trip), ['action' => 'reset']);
        $this->patch(route('trips.stops', $trip), ['action' => 'pin', 'poi' => $other]);
        $stops = $this->plan($trip->refresh())['stops'];

        $this->assertSame($other, $stops[0]['poi_id']);
        $this->assertTrue($stops[0]['pinned']);
        $this->assertSame([], (array) $trip->refresh()->excluded_stops);
    }

    public function test_travel_tips_are_generated_on_request()
    {
        TripAdvisor::fake([['tips' => [
            ['category' => 'costs', 'title' => 'Koop een vignet voor Zwitserland', 'body' => 'Het e-vignet kost ongeveer 40 euro.'],
        ]]]);
        $this->fake();
        $vehicle = $this->signInWithVehicle(['brand' => 'Volkswagen']);
        $trip = $this->trip($vehicle);
        $this->plan($trip);

        $this->post(route('trips.tips', $trip))->assertRedirect();

        $tips = $trip->refresh()->tips;
        $this->assertSame('Koop een vignet voor Zwitserland', $tips['items'][0]['title']);
        TripAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"Switzerland"')
            && str_contains($prompt->prompt, '"brand": "Volkswagen"')
            && ! str_contains($prompt->prompt, $vehicle->license_plate));

        // Not again right away.
        $this->post(route('trips.tips', $trip));
        TripAdvisor::assertPrompted(fn () => true);
        $this->assertSame($tips, $trip->refresh()->tips);
    }

    public function test_an_electric_vehicle_charges_at_a_fast_charger()
    {
        $this->fake();
        // A battery of 60 kWh at 19 kWh/100 km, full when leaving: it runs low after about 280 km.
        $vehicle = $this->signInWithVehicle(['powertrain' => 'electricity', 'fuel_types' => ['Electricity DC'], 'tank_capacity' => 60]);

        $route = $this->plan($this->trip($vehicle, ['fuel_level' => 100]));

        $charging = collect($route['stops'])->first(fn (array $stop) => in_array('fuel', $stop['needs'], true));
        $this->assertSame(1, $this->osmId($charging['poi_id']));
        $this->assertContains('charge', $charging['features']);
        // After about 200 km, up to 80%: from about 22 kWh to 48 kWh.
        $this->assertEqualsWithDelta(26, $charging['fuel_liters'], 3);
        // 26 kWh at 120 kW times 0.7 takes 19 minutes, and 5 minutes to plug in and pay.
        $this->assertSame(24, $charging['minutes']);
        $this->assertTrue(Trip::sole()->plan['vehicle']['electric']);
    }

    public function test_the_prices_of_the_united_kingdom_are_in_euros()
    {
        Http::fake([
            '*ecb.europa.eu*' => Http::response("<Cube currency='USD' rate='1.1'/><Cube currency='GBP' rate='0.8'/>"),
            '*' => Http::response(['stations' => [
                ['location' => ['latitude' => 51.5, 'longitude' => -0.12], 'prices' => ['E10' => 160.0, 'B7' => 170.0]],
                ['location' => ['latitude' => 55.9, 'longitude' => -3.2], 'prices' => ['E10' => 150.0]],
            ]]),
        ]);

        $stations = (new UkRetailers)->near(51.5, -0.12, 'e10');

        // 160 pence at 0.8 pounds a euro, the same station in every feed.
        $this->assertSame(2.0, $stations[0]['price']);
        $this->assertSame('Unleaded 95 (E10)', $stations[0]['fuel_type']);
        $this->assertCount(count(config('trip_planner.prices.uk_feeds')), $stations);
    }
}
