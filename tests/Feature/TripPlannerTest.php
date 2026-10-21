<?php

namespace Tests\Feature;

use App\Jobs\PlanTrip;
use App\Models\Poi;
use App\Models\PoiTile;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripPlanner\ApiUsage;
use App\Services\TripPlanner\TripPlanner;
use App\Support\Roads;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TripPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-19 12:00:00');
        config(['trip_planner.tomtom.key' => 'test-key']);
        // Each test fakes the services it uses, before the catch-all of the vehicle statuses.
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $stubs
     */
    private function fake(array $stubs = []): void
    {
        Http::fake([...$stubs, '*' => Http::response([], 200)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'name' => '',
            'timeMode' => 'departure',
            'timeAt' => '2026-10-22T08:00',
            'fuelLevel' => 100,
            'waypoints' => [
                ['label' => 'Utrecht', 'latitude' => 52.0, 'longitude' => 5.0],
                ['label' => 'Reims', 'latitude' => 48.0, 'longitude' => 5.0],
            ],
            ...$overrides,
        ];
    }

    /**
     * A TomTom route straight south along longitude 5, at 120 km/h.
     *
     * @return array<string, mixed>
     */
    private function tomTomRoute(): array
    {
        $points = [];

        for ($lat = 52.0; $lat >= 47.9999; $lat -= 0.01) {
            $points[] = ['latitude' => round($lat, 4), 'longitude' => 5.0];
        }

        $last = count($points) - 1;
        $length = 445000;
        $seconds = (int) ($length / 1000 / 120 * 3600);

        return [
            'summary' => [
                'lengthInMeters' => $length,
                'travelTimeInSeconds' => $seconds,
                'trafficDelayInSeconds' => 0,
                'departureTime' => '2026-10-22T08:00:00+02:00',
                'arrivalTime' => '2026-10-22T11:42:30+02:00',
            ],
            'legs' => [['points' => $points]],
            'sections' => [
                ['startPointIndex' => 0, 'endPointIndex' => 150, 'sectionType' => 'COUNTRY', 'countryCode' => 'NLD'],
                ['startPointIndex' => 150, 'endPointIndex' => $last, 'sectionType' => 'COUNTRY', 'countryCode' => 'FRA'],
                ['startPointIndex' => 0, 'endPointIndex' => $last, 'sectionType' => 'MOTORWAY'],
                ['startPointIndex' => 300, 'endPointIndex' => $last, 'sectionType' => 'TOLL_ROAD'],
            ],
            'guidance' => ['instructions' => [
                ['routeOffsetInMeters' => 0, 'travelTimeInSeconds' => 0, 'pointIndex' => 0, 'roadNumbers' => ['A2'], 'countryCode' => 'NLD'],
                ['routeOffsetInMeters' => 166500, 'travelTimeInSeconds' => 4995, 'pointIndex' => 150, 'roadNumbers' => ['A26'], 'countryCode' => 'FRA'],
                ['routeOffsetInMeters' => $length, 'travelTimeInSeconds' => $seconds, 'pointIndex' => $last],
            ]],
        ];
    }

    /**
     * Service areas on both sides of the motorway (driving south, the one to
     * the west is on the right), and a gas station near an exit.
     *
     * @return list<array<string, mixed>>
     */
    private function overpassElements(): array
    {
        return [
            ['type' => 'way', 'id' => 1, 'center' => ['lat' => 49.80, 'lon' => 4.998], 'tags' => ['highway' => 'services', 'name' => 'Aire de l\'Ouest']],
            ['type' => 'node', 'id' => 2, 'lat' => 49.8005, 'lon' => 4.9975, 'tags' => ['amenity' => 'fuel', 'brand' => 'TotalEnergies', 'fuel:e10' => 'yes', 'opening_hours' => '24/7']],
            ['type' => 'node', 'id' => 3, 'lat' => 49.7995, 'lon' => 4.9978, 'tags' => ['amenity' => 'restaurant', 'name' => 'Brasserie']],
            ['type' => 'way', 'id' => 4, 'center' => ['lat' => 49.70, 'lon' => 5.002], 'tags' => ['highway' => 'services', 'name' => 'Aire de l\'Est']],
            ['type' => 'node', 'id' => 5, 'lat' => 50.5, 'lon' => 5.0001, 'tags' => ['barrier' => 'toll_booth']],
            ['type' => 'node', 'id' => 6, 'lat' => 51.0, 'lon' => 5.3, 'tags' => ['amenity' => 'fuel', 'name' => 'Far away']],
            ['type' => 'node', 'id' => 7, 'lat' => 49.60, 'lon' => 5.0, 'tags' => ['highway' => 'motorway_junction', 'ref' => '12', 'name' => 'Sedan']],
            ['type' => 'node', 'id' => 8, 'lat' => 49.60, 'lon' => 5.02, 'tags' => ['amenity' => 'fuel', 'brand' => 'Intermarché', 'shop' => 'convenience']],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $prices  The French prices of gas stations.
     */
    private function fakeApis(array $prices = []): void
    {
        $this->fake([
            '*api.tomtom.com/routing/*' => Http::response(['routes' => [$this->tomTomRoute()]]),
            '*/api/interpreter' => Http::response(['elements' => $this->overpassElements()]),
            '*data.economie.gouv.fr/*' => Http::response(['results' => $prices]),
        ]);
    }

    private function trip(Vehicle $vehicle, array $attributes = []): Trip
    {
        $trip = Trip::create([
            'user_id' => $vehicle->user_id,
            'vehicle_id' => $vehicle->id,
            'time_mode' => 'departure',
            'time_at' => '2026-10-22 06:00:00',
            'fuel_level' => 100,
            ...$attributes,
        ]);

        $trip->waypoints()->createMany([
            ['position' => 0, 'label' => 'Utrecht, Nederland', 'latitude' => 52.0, 'longitude' => 5.0],
            ['position' => 1, 'label' => 'Reims, France', 'latitude' => 48.0, 'longitude' => 5.0],
        ]);

        return $trip;
    }

    private function plan(Trip $trip): Trip
    {
        (new PlanTrip($trip))->handle(app(TripPlanner::class));

        return $trip->refresh();
    }

    private function overpassRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'interpreter'))->count();
    }

    public function test_the_planner_starts_at_home_with_the_current_vehicle()
    {
        $this->fake();
        $vehicle = $this->signInWithVehicle();
        auth()->user()->update(['city' => 'Utrecht', 'latitude' => 52.09, 'longitude' => 5.12]);

        $this->get(route('trips.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Trips/Planner')
                ->where('vehicle', $vehicle->full_name)
                ->where('form.waypoints.0.label', 'Utrecht')
                ->where('form.waypoints.1', null)
                ->where('form.timeAt', '2026-10-19T15:00')
                ->where('shields.countries.NL.0', ['prefix' => 'A', 'image' => 'red-500_none', 'text' => '#ffffff'])
                ->where('shields.countries.ES.0.prefix', 'AP')
                ->where('shields.european', ['image' => 'green-600_none', 'text' => '#ffffff'])
                ->where('trip', null));
    }

    public function test_a_trip_is_stored_for_the_current_vehicle_and_planned_in_the_background()
    {
        Queue::fake();
        $this->fake();
        $vehicle = $this->signInWithVehicle();

        $response = $this->post(route('trips.store'), $this->form(['waypoints' => [
            ['label' => 'Utrecht', 'latitude' => 52.0, 'longitude' => 5.0],
            ['label' => 'Antwerpen', 'latitude' => 51.2, 'longitude' => 4.4, 'stayMinutes' => 30],
            ['label' => 'Reims', 'latitude' => 48.0, 'longitude' => 5.0, 'stayMinutes' => 30],
        ]]));

        $trip = Trip::sole();
        $response->assertRedirect(route('trips.show', $trip));

        $this->assertSame($vehicle->id, $trip->vehicle_id);
        $this->assertSame(Trip::STATUS_PLANNING, $trip->status);
        // Entered in Dutch time.
        $this->assertSame('2026-10-22 06:00:00', $trip->time_at->format('Y-m-d H:i:s'));
        $this->assertSame([0, 30, 0], $trip->waypoints->pluck('stay_minutes')->all());
        Queue::assertPushed(PlanTrip::class, fn (PlanTrip $job) => $job->trip->is($trip));
    }

    public function test_a_place_has_to_be_picked_from_the_list()
    {
        Queue::fake();
        $this->fake();
        $this->signInWithVehicle();

        $this->post(route('trips.store'), $this->form(['waypoints' => [
            ['label' => 'Utrecht', 'latitude' => 52.0, 'longitude' => 5.0],
            ['label' => 'Reims', 'latitude' => null, 'longitude' => null],
        ]]))->assertSessionHasErrors('waypoints.1.latitude');

        $this->assertSame(0, Trip::count());
    }

    public function test_the_trip_of_another_user_cannot_be_seen()
    {
        $this->fake();
        $this->signInWithVehicle();
        $trip = $this->trip(Vehicle::factory()->for(User::factory())->create());

        $this->get(route('trips.show', $trip))->assertNotFound();
        $this->delete(route('trips.destroy', $trip))->assertNotFound();
    }

    public function test_the_routes_are_planned_with_their_stops()
    {
        $this->fakeApis();
        $vehicle = $this->signInWithVehicle(['tank_capacity' => 50, 'powertrain' => 'gasoline']);
        $trip = $this->plan($this->trip($vehicle, ['fuel_level' => 60]));

        $this->assertSame(Trip::STATUS_PLANNED, $trip->status);

        $route = $trip->plan['routes'][0];
        $this->assertSame(445000, $route['distance']);
        $this->assertSame(['NL', 'FR'], $route['countries']);
        $this->assertSame([['label' => 'A2', 'classes' => 'bg-red-500 text-white'], ['label' => 'A26', 'classes' => 'bg-red-500 text-white']], $route['roads']);
        $this->assertGreaterThan(100000, $route['toll_distance']);
        $this->assertSame(['start', 'end'], array_column($route['toll_points'], 'type'));
        $this->assertSame('2026-10-22T08:00:00+02:00', $route['departure_at']);

        // Without prices, the service area on the right side of the road is
        // chosen: the one on the other side is passed by.
        $this->assertCount(1, $route['stops']);
        $stop = $route['stops'][0];
        $this->assertSame("Aire de l'Ouest", $stop['name']);
        $this->assertSame('TotalEnergies', $stop['brand']);
        $this->assertSame(['label' => 'A26', 'classes' => 'bg-red-500 text-white'], $stop['road']);
        $this->assertSame(['fuel', 'coffee', 'meal', 'toilet', 'parking'], $stop['features']);
        $this->assertSame(['break', 'toilet', 'fuel'], $stop['needs']);
        $this->assertSame($route['stop_seconds'], $stop['minutes'] * 60);
        $this->assertSame(
            CarbonImmutable::parse($route['departure_at'])->addSeconds($route['drive_seconds'] + $route['stop_seconds'])->toIso8601String(),
            CarbonImmutable::parse($route['arrival_at'])->toIso8601String(),
        );

        // The places a stop can be made at, and the toll booth passed through.
        $osmIds = fn (array $ids) => Poi::whereIn('id', $ids)->pluck('osm_id')->sort()->values()->all();
        $this->assertSame([1, 8], $osmIds(array_column($route['candidates'], 'id')));
        $this->assertSame([5], $osmIds($route['toll_booths']));
        $this->assertNotContains(6, $osmIds($route['pois']));
        $this->assertNotContains(7, $osmIds($route['pois']));
    }

    public function test_a_cheaper_gas_station_off_the_motorway_is_worth_the_detour()
    {
        $this->fakeApis([
            ['geom' => ['lat' => 49.8005, 'lon' => 4.9975], 'e10_prix' => 2.20, 'e10_maj' => '2026-10-19T08:00:00+00:00'],
            ['geom' => ['lat' => 49.60, 'lon' => 5.02], 'e10_prix' => 1.70, 'e10_maj' => '2026-10-19T08:00:00+00:00'],
        ]);
        $vehicle = $this->signInWithVehicle(['tank_capacity' => 50, 'powertrain' => 'gasoline']);
        $trip = $this->plan($this->trip($vehicle, ['fuel_level' => 60]));

        $route = $trip->plan['routes'][0];
        $stop = $route['stops'][0];

        $this->assertSame('Intermarché', $stop['brand']);
        $this->assertSame('12 Sedan', $stop['exit']);
        $this->assertSame(['break', 'fuel'], $stop['needs']);
        $this->assertGreaterThan(120, $stop['detour_seconds']);
        $this->assertSame(['price' => 1.7, 'source' => 'station', 'updated_at' => '2026-10-19T08:00:00+00:00', 'fuel_type' => 'Unleaded 95 (E10)'], $route['prices'][$stop['poi_id']]);

        // Shown with the name of the fuel in the language of the user.
        $this->get(route('trips.show', $trip))
            ->assertInertia(fn (Assert $page) => $page->where("trip.plan.routes.0.prices.{$stop['poi_id']}.fuel_type", 'Unleaded 95 (E10)'));
        // The detour takes time.
        $this->assertSame($stop['minutes'] * 60 + $stop['detour_seconds'], $route['stop_seconds']);
    }

    /**
     * Plans the trip with other places from OpenStreetMap, and the candidates of its route by name.
     *
     * @param  list<array<string, mixed>>  $elements
     * @return array<string, list<string>>
     */
    private function candidatesWith(array $elements): array
    {
        $this->fake([
            '*api.tomtom.com/routing/*' => Http::response(['routes' => [$this->tomTomRoute()]]),
            '*/api/interpreter' => Http::response(['elements' => $elements]),
        ]);
        $trip = $this->plan($this->trip($this->signInWithVehicle()));

        return collect($trip->plan['routes'][0]['candidates'])
            ->mapWithKeys(fn (array $candidate) => [Poi::find($candidate['id'])->name => $candidate['features']])
            ->all();
    }

    public function test_a_service_area_for_the_other_direction_is_passed_by()
    {
        // On the right side of the road, but tagged for traffic to the north.
        $candidates = $this->candidatesWith([
            ['type' => 'way', 'id' => 1, 'center' => ['lat' => 49.80, 'lon' => 4.998], 'tags' => ['highway' => 'services', 'name' => 'Noord', 'direction' => 'north']],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 49.60, 'lon' => 4.998], 'tags' => ['highway' => 'services', 'name' => 'Zuid', 'direction' => 'south']],
            // On the other side, close to the road.
            ['type' => 'way', 'id' => 3, 'center' => ['lat' => 49.40, 'lon' => 5.0005], 'tags' => ['highway' => 'services', 'name' => 'Overkant']],
        ]);

        $this->assertSame(['Zuid'], array_keys($candidates));
    }

    public function test_a_gas_station_belongs_to_the_service_area_on_its_side_of_the_road()
    {
        $candidates = $this->candidatesWith([
            ['type' => 'way', 'id' => 1, 'center' => ['lat' => 49.80, 'lon' => 4.9985], 'tags' => ['highway' => 'services', 'name' => 'West', 'toilets' => 'yes']],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 49.80, 'lon' => 5.0015], 'tags' => ['highway' => 'services', 'name' => 'Oost']],
            // Closer to the area on the right side, but across the road.
            ['type' => 'node', 'id' => 3, 'lat' => 49.80, 'lon' => 5.0008, 'tags' => ['amenity' => 'fuel', 'brand' => 'Shell']],
        ]);

        // Without a gas station, and with the toilet it is tagged with.
        $this->assertSame(['West' => ['coffee', 'toilet', 'parking']], $candidates);
    }

    public function test_a_service_area_has_a_toilet_only_when_it_is_known()
    {
        $candidates = $this->candidatesWith([
            ['type' => 'way', 'id' => 1, 'center' => ['lat' => 49.80, 'lon' => 4.998], 'tags' => ['highway' => 'services', 'name' => 'Kaal']],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 49.60, 'lon' => 4.998], 'tags' => ['highway' => 'services', 'name' => 'Met restaurant']],
            ['type' => 'node', 'id' => 3, 'lat' => 49.6005, 'lon' => 4.9978, 'tags' => ['amenity' => 'fast_food', 'name' => 'Frites']],
        ]);

        $this->assertSame(['coffee', 'parking'], $candidates['Kaal']);
        $this->assertSame(['coffee', 'meal', 'toilet', 'parking'], $candidates['Met restaurant']);
    }

    public function test_the_facilities_of_a_service_area_are_shown()
    {
        $this->fake([
            '*api.tomtom.com/routing/*' => Http::response(['routes' => [$this->tomTomRoute()]]),
            '*/api/interpreter' => Http::response(['elements' => [
                ['type' => 'way', 'id' => 1, 'center' => ['lat' => 49.80, 'lon' => 4.998], 'tags' => ['highway' => 'services', 'name' => 'West', 'internet_access' => 'wlan']],
                ['type' => 'node', 'id' => 2, 'lat' => 49.8005, 'lon' => 4.9975, 'tags' => ['amenity' => 'fuel', 'shop' => 'convenience', 'car_wash' => 'yes']],
                ['type' => 'node', 'id' => 3, 'lat' => 49.7995, 'lon' => 4.9978, 'tags' => ['leisure' => 'playground']],
                ['type' => 'node', 'id' => 4, 'lat' => 49.7998, 'lon' => 4.9972, 'tags' => ['amenity' => 'charging_station']],
            ]]),
        ]);
        $trip = $this->plan($this->trip($this->signInWithVehicle()));

        $candidate = $trip->plan['routes'][0]['candidates'][0];
        $this->assertEqualsCanonicalizing(['wifi', 'shop', 'car_wash', 'playground', 'charging'], $candidate['services']);
        // A facility is not shown on the map on its own.
        $this->assertNotContains(4, Poi::whereIn('id', $trip->plan['routes'][0]['pois'])->pluck('osm_id')->all());
    }

    public function test_arriving_at_a_time_departs_early_enough_for_the_stops()
    {
        $this->fakeApis();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->plan($this->trip($vehicle, ['time_mode' => 'arrival', 'time_at' => '2026-10-22 16:00:00']));

        $route = $trip->plan['routes'][0];
        $this->assertSame('2026-10-22T16:00:00+00:00', $route['arrival_at']);
        $this->assertSame(
            CarbonImmutable::parse('2026-10-22 16:00:00')->subSeconds($route['drive_seconds'] + $route['stop_seconds'])->toIso8601String(),
            $route['departure_at'],
        );
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'arriveAt=2026-10-22T18%3A00%3A00%2B02%3A00'));
    }

    public function test_the_places_along_a_route_are_fetched_once()
    {
        $this->fakeApis();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->trip($vehicle);

        $this->plan($trip);
        $fetched = $this->overpassRequests();
        $this->plan($trip);

        $this->assertGreaterThan(0, $fetched);
        $this->assertSame($fetched, $this->overpassRequests());
        // Fetched in whole blocks of 4 by 4 tiles.
        $this->assertSame(0, PoiTile::count() % 16);

        // A month later the tiles are fetched again.
        $this->travel(31)->days();
        $this->plan($trip);
        $this->assertSame(2 * $fetched, $this->overpassRequests());
    }

    public function test_the_routes_are_kept_when_the_places_cannot_be_fetched()
    {
        $this->fake([
            '*api.tomtom.com/routing/*' => Http::response(['routes' => [$this->tomTomRoute()]]),
            '*/api/interpreter' => Http::response('Gateway Time-out', 504),
        ]);
        $vehicle = $this->signInWithVehicle();
        $trip = $this->plan($this->trip($vehicle));

        $this->assertSame(Trip::STATUS_PLANNED, $trip->status);
        $this->assertSame(445000, $trip->plan['routes'][0]['distance']);
        $this->assertSame(['no_places'], $trip->plan['routes'][0]['warnings']);
        // Each block was tried at each server.
        $this->assertSame(0, $this->overpassRequests() % count(config('trip_planner.overpass.servers')));
    }

    public function test_a_planning_of_a_trip_that_changed_in_the_meantime_is_thrown_away()
    {
        $this->fakeApis();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->trip($vehicle, ['status' => Trip::STATUS_PLANNING]);
        $job = new PlanTrip($trip);

        // The trip changes while the job runs.
        Trip::whereKey($trip->id)->update(['plan_version' => 1]);
        $job->handle(app(TripPlanner::class));

        $this->assertSame(Trip::STATUS_PLANNING, $trip->refresh()->status);
        $this->assertNull($trip->plan);
    }

    public function test_a_trip_that_cannot_be_routed_fails_with_a_reason()
    {
        $this->fake(['*api.tomtom.com/routing/*' => Http::response(['detailedError' => ['message' => 'No route']], 400)]);
        $vehicle = $this->signInWithVehicle();
        $trip = $this->plan($this->trip($vehicle));

        $this->assertSame(Trip::STATUS_FAILED, $trip->status);
        $this->get(route('trips.show', $trip))
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.status', 'failed')
                ->where('trip.error', 'There is no route between these places.'));
    }

    public function test_a_planned_trip_shows_its_routes_and_places()
    {
        $this->fakeApis();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->plan($this->trip($vehicle));

        $this->get(route('trips.show', $trip))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Trips/Planner')
                ->where('trip.name', 'Utrecht → Reims')
                ->where('form.timeAt', '2026-10-22T08:00')
                ->has('trip.plan.routes', 1)
                ->missing('trip.plan.routes.0.pois')
                ->has('trip.plan.routes.0.poiIds')
                ->where('pois', fn ($pois) => collect($pois)->contains(fn ($poi) => $poi['kind'] === 'gas_station'
                    && $poi['brand'] === 'TotalEnergies'
                    && $poi['details'] === ['opening_hours' => '24/7', 'fuels' => ['Unleaded 95 (E10)']])));

        $this->get(route('trips.logo', ['brand' => 'TotalEnergies']))
            ->assertRedirect(asset('images/gas-stations/totalenergies.png'));

        $this->get(route('trips.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Trips/Index')
                ->where('trips.0.name', 'Utrecht → Reims')
                ->where('trips.0.distance', 445000));
    }

    public function test_my_trips_are_the_trips_of_the_current_vehicle()
    {
        $this->fake();
        $vehicle = $this->signInWithVehicle();
        $other = Vehicle::factory()->for(auth()->user())->create(['created_at' => now()->subYear()]);
        $this->trip($vehicle);
        $this->trip($other);

        $this->get(route('trips.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('trips', 1)
                ->where('trips.0.vehicle', $vehicle->full_name));
    }

    public function test_the_chosen_route_is_remembered()
    {
        $this->fake();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->trip($vehicle, ['status' => Trip::STATUS_PLANNED, 'plan' => ['routes' => [['pois' => []], ['pois' => []]]]]);

        $this->patch(route('trips.route', $trip), ['route' => 1])->assertRedirect();
        $this->assertSame(1, $trip->refresh()->selected_route);

        $this->patch(route('trips.route', $trip), ['route' => 2])->assertSessionHasErrors('route');
    }

    public function test_a_trip_is_replanned_when_it_changes()
    {
        Queue::fake();
        $this->fake();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->trip($vehicle, ['status' => Trip::STATUS_PLANNED]);

        $this->put(route('trips.update', $trip), $this->form(['name' => 'Vakantie']))
            ->assertRedirect(route('trips.show', $trip));

        $this->assertSame('Vakantie', $trip->refresh()->name);
        $this->assertSame(Trip::STATUS_PLANNING, $trip->status);
        $this->assertSame(1, $trip->plan_version);
        $this->assertSame(['Utrecht', 'Reims'], $trip->waypoints->pluck('label')->all());
        Queue::assertPushed(PlanTrip::class);
    }

    public function test_a_trip_can_be_deleted()
    {
        $this->fake();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->trip($vehicle);

        $this->delete(route('trips.destroy', $trip))->assertRedirect(route('trips.index'));

        $this->assertModelMissing($trip);
    }

    public function test_places_are_looked_up_while_typing()
    {
        $this->fake(['*api.tomtom.com/search/*' => Http::response(['results' => [
            ['position' => ['lat' => 49.26, 'lon' => 4.03], 'address' => ['freeformAddress' => 'Reims', 'country' => 'France', 'countryCode' => 'FR']],
        ]])]);
        $this->signInWithVehicle();

        $this->getJson(route('trips.geocode', ['query' => 'Reims']))
            ->assertOk()
            ->assertExactJson([['label' => 'Reims, FR', 'latitude' => 49.26, 'longitude' => 4.03]]);
    }

    public function test_the_current_location_gets_its_address()
    {
        $this->fake(['*api.tomtom.com/search/2/reverseGeocode/*' => Http::response(['addresses' => [
            ['address' => ['freeformAddress' => 'Oostkade 1, 3221 AA Hellevoetsluis', 'country' => 'Nederland', 'countryCode' => 'NL']],
        ]])]);
        $this->signInWithVehicle();

        $this->getJson(route('trips.reverse-geocode', ['latitude' => 51.83, 'longitude' => 4.14]))
            ->assertOk()
            ->assertExactJson(['label' => 'Oostkade 1, 3221 AA Hellevoetsluis, NL', 'latitude' => 51.83, 'longitude' => 4.14]);
    }

    public function test_the_places_of_the_countries_are_fetched_ahead_of_time()
    {
        $this->fake(['*/api/interpreter' => Http::response(['elements' => $this->overpassElements()])]);
        config(['trip_planner.warm.countries' => ['LU' => [49.5, 5.75, 50.0, 6.25]]]);

        $this->artisan('trip-planner:warm')->assertSuccessful();
        $fetched = $this->overpassRequests();
        $this->artisan('trip-planner:warm')->assertSuccessful();

        $this->assertGreaterThan(0, $fetched);
        $this->assertSame($fetched, $this->overpassRequests());
        $this->assertContains('199:23', PoiTile::pluck('tile')->all());
    }

    public function test_road_numbers_are_shown_as_on_the_road_signs_of_their_country()
    {
        $this->assertSame(['label' => 'A2', 'classes' => 'bg-red-500 text-white'], Roads::badge('NL', 'A2'));
        $this->assertSame(['label' => 'A2', 'classes' => 'bg-blue-500 text-white'], Roads::badge('DE', 'A2'));
        $this->assertSame(['label' => 'B9', 'classes' => 'bg-yellow-500 text-black'], Roads::badge('DE', 'B9'));
        $this->assertSame(['label' => 'E19', 'classes' => 'bg-green-600 text-white'], Roads::badge('FR', 'E19'));
        $this->assertSame(['label' => 'AP-7', 'classes' => 'bg-blue-500 text-white'], Roads::badge('ES', 'AP-7'));
    }

    public function test_the_trip_planner_is_in_the_navigation()
    {
        $this->fake();
        $this->signInWithVehicle();

        $this->get(route('trips.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigation', fn ($groups) => collect($groups)->contains(
                    fn ($group) => collect($group['items'])->pluck('href')->contains('/trips/create'),
                )));
    }

    public function test_the_fuel_prices_and_the_traffic_are_refreshed_on_request()
    {
        $this->fakeApis();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->plan($this->trip($vehicle));
        Queue::fake();

        $this->post(route('trips.refresh', $trip))->assertRedirect();

        $this->assertSame(Trip::STATUS_PLANNING, $trip->refresh()->status);
        Queue::assertPushed(PlanTrip::class, fn (PlanTrip $job) => $job->trip->is($trip) && $job->fresh);

        // Not again while it is being planned.
        $this->post(route('trips.refresh', $trip))->assertRedirect();
        Queue::assertPushed(PlanTrip::class, 1);
    }

    public function test_choosing_a_stop_plans_again_with_the_same_routes()
    {
        $this->fakeApis();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->plan($this->trip($vehicle));
        $routing = fn () => Http::recorded(fn (Request $request) => str_contains($request->url(), 'calculateRoute'))->count();
        $calls = $routing();

        $this->plan($trip);
        $this->assertSame($calls, $routing());

        // Refreshing asks TomTom again.
        (new PlanTrip($trip, fresh: true))->handle(app(TripPlanner::class));
        $this->assertGreaterThan($calls, $routing());
    }

    public function test_the_progress_of_the_planning_is_kept_with_the_trip()
    {
        $seen = [];
        $this->fake([
            '*api.tomtom.com/routing/*' => Http::response(['routes' => [$this->tomTomRoute()]]),
            '*/api/interpreter' => function () use (&$seen) {
                $seen[] = Trip::sole()->progress;

                return Http::response(['elements' => $this->overpassElements()]);
            },
        ]);
        $trip = $this->plan($this->trip($this->signInWithVehicle()));

        $this->assertSame('places', $seen[0]['stage']);
        $this->assertSame(0, $seen[0]['done']);
        $this->assertGreaterThan(0, $seen[0]['total']);
        // Done: no progress any more.
        $this->assertSame(Trip::STATUS_PLANNED, $trip->status);
        $this->assertNull($trip->progress);
    }

    public function test_a_planning_that_is_no_longer_heard_of_has_failed()
    {
        $this->fake();
        $vehicle = $this->signInWithVehicle();
        $trip = $this->trip($vehicle, ['status' => Trip::STATUS_ROUTED, 'progress' => ['value' => 0.4, 'stage' => 'places']]);
        Trip::whereKey($trip->id)->update(['updated_at' => now()->subMinutes(Trip::STALE_AFTER_MINUTES + 1)]);

        $this->get(route('trips.show', $trip))
            ->assertInertia(fn (Assert $page) => $page
                ->where('trip.status', 'failed')
                ->where('trip.error', 'Planning the trip took too long. Please try again.'));
    }

    public function test_a_job_that_did_not_finish_fails_the_trip()
    {
        $this->fake();
        $trip = $this->trip($this->signInWithVehicle(), ['status' => Trip::STATUS_ROUTED]);

        (new PlanTrip($trip))->failed(new \RuntimeException('Timed out'));

        $this->assertSame(Trip::STATUS_FAILED, $trip->refresh()->status);
    }

    public function test_fetching_the_places_stops_in_time()
    {
        config(['trip_planner.overpass.budget' => 0]);
        $this->fakeApis();
        $trip = $this->plan($this->trip($this->signInWithVehicle()));

        // Planned without places, rather than not at all.
        $this->assertSame(Trip::STATUS_PLANNED, $trip->status);
        $this->assertSame(['no_places'], $trip->plan['routes'][0]['warnings']);
        $this->assertSame(0, $this->overpassRequests());
    }

    public function test_the_costs_of_a_route_are_estimated()
    {
        $this->fakeApis([
            ['geom' => ['lat' => 49.8005, 'lon' => 4.9975], 'e10_prix' => 2.00, 'e10_maj' => '2026-10-19T08:00:00+00:00'],
            ['geom' => ['lat' => 49.60, 'lon' => 5.02], 'e10_prix' => 1.80, 'e10_maj' => '2026-10-19T08:00:00+00:00'],
        ]);
        $vehicle = $this->signInWithVehicle(['tank_capacity' => 50, 'powertrain' => 'gasoline']);
        $costs = $this->plan($this->trip($vehicle, ['fuel_level' => 60]))->plan['routes'][0]['costs'];

        // 445 km at the consumption of the vehicle, at the price of the fuel stop.
        $this->assertGreaterThan(0, $costs['energy']);
        $this->assertSame(1.8, $costs['fuel_price']);
        $this->assertEqualsWithDelta($costs['energy'] * 1.8, $costs['fuel'], 0.01);
        // The toll of the French motorway is not known.
        $this->assertFalse($costs['complete']);
        $this->assertSame($costs['fuel'], $costs['total']);
    }

    public function test_the_traffic_is_left_out_near_the_daily_limit_of_tomtom()
    {
        $this->fakeApis();
        DB::table('trip_planner_api_calls')->insert([
            'date' => now('UTC')->toDateString(), 'service' => ApiUsage::TOMTOM, 'kind' => 'routing',
            'succeeded' => 2400, 'failed' => 0, 'milliseconds' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $trip = $this->plan($this->trip($this->signInWithVehicle()));

        $this->assertTrue($trip->plan['traffic_skipped']);
        $this->assertSame(0, Http::recorded(fn (Request $request) => str_contains($request->url(), '/traffic/'))->count());
        // The route itself is still planned.
        $this->assertSame(Trip::STATUS_PLANNED, $trip->status);
    }

    public function test_the_calls_to_tomtom_and_overpass_are_counted()
    {
        Http::globalMiddleware(ApiUsage::middleware());
        $this->fakeApis();
        $this->plan($this->trip($this->signInWithVehicle()));

        $this->assertGreaterThan(0, ApiUsage::tomTomToday());
        $this->assertTrue(DB::table('trip_planner_api_calls')->where('service', ApiUsage::OVERPASS)->where('succeeded', '>', 0)->exists());

        $this->artisan('trip-planner:usage')
            ->expectsOutputToContain('TomTom today: '.ApiUsage::tomTomToday().' of 2500 calls')
            ->assertSuccessful();
    }
}
