<?php

namespace Tests\Feature;

use App\Http\Controllers\RefuelingController;
use App\Models\FuelPrice;
use App\Models\Poi;
use App\Models\Refueling;
use App\Models\StationPrice;
use App\Models\StationPriceHistory;
use App\Models\Vehicle;
use App\Models\VehicleNotification;
use App\Services\DashboardService;
use App\Services\FuelAdviceFacts;
use App\Services\LowTank;
use App\Services\NotificationCenter;
use App\Services\TimelineService;
use App\Services\TripPlanner\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the prices per gas station are used for besides the map: choosing the
 * gas station of a refueling, the fuel advice, a nearly empty tank, the
 * dashboard, the predictions and the history of the prices. In Paris, where
 * the prices come from the import, so that nothing is asked elsewhere.
 */
class NearbyStationsTest extends TestCase
{
    use RefreshDatabase;

    private int $osm = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-24 12:00:00');
        Http::swap(new Factory);
        Http::fake(['www.anwb.nl/*' => Http::response('', 404)]);
    }

    /**
     * A vehicle of an owner who lives in Paris, refueled at Total Rivoli
     * twice, with the tank nearly empty by now.
     */
    private function parisian(): Vehicle
    {
        $vehicle = $this->signInWithVehicle(['country_registration' => 'france', 'fuel_types' => ['Unleaded 95 (E10)']]);
        $vehicle->user->update(['latitude' => 48.86, 'longitude' => 2.35, 'city' => 'Paris']);

        foreach ([['2026-10-01', 9400, 10000], ['2026-10-11', 10000, 10600]] as [$date, $from, $to]) {
            Refueling::factory()->for($vehicle)->create([
                'date' => $date,
                'mileage_begin' => $from,
                'mileage_end' => $to,
                'percentage' => 100,
                'fuel_consumption' => 6,
                'fuel_type' => 'Unleaded 95 (E10)',
                'gas_station' => 'Total Rivoli',
                'amount' => 36,
                'unit_price' => 1.899,
                'total_price' => 68.36,
            ]);
        }

        return $vehicle->fresh();
    }

    private function station(string $name, ?string $brand, float $latitude, float $longitude, float $price, array $tags = []): Poi
    {
        $poi = Poi::create([
            'osm_type' => 'n',
            'osm_id' => $this->osm++,
            'kind' => Poi::GAS_STATION,
            'name' => $name,
            'brand' => $brand,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'tags' => ['addr:country' => 'FR', ...$tags],
            'tile' => Geo::tile($latitude, $longitude),
        ]);

        StationPrice::query()->insert([
            'country' => 'FR', 'source' => 'PrixCarburants', 'source_id' => (string) $poi->id, 'latitude' => $latitude, 'longitude' => $longitude,
            'fuel' => 'e10', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => $price, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $poi;
    }

    /**
     * Three gas stations near the home in Paris: the usual one the dearest.
     */
    private function stations(): array
    {
        return [
            'usual' => $this->station('Total Rivoli', 'Total', 48.861, 2.351, 1.899, ['addr:city' => 'Paris']),
            'cheap' => $this->station('Esso Bastille', 'Esso', 48.853, 2.369, 1.799, ['addr:city' => 'Paris', 'addr:street' => 'Rue de Lyon']),
            'middle' => $this->station('BP Opéra', 'BP', 48.871, 2.332, 1.849),
            // Too far from home.
            'far' => $this->station('Avia Orléans', 'Avia', 47.90, 1.90, 1.599),
        ];
    }

    public function test_the_gas_station_of_a_refueling_can_be_chosen_from_the_ones_nearby()
    {
        $vehicle = $this->parisian();
        $stations = $this->stations();

        $nearby = $this->getJson(route('refuelings.nearby-stations', ['latitude' => 48.8611, 'longitude' => 2.3511, 'fuel_type' => 'Unleaded 95 (E10)']))
            ->assertOk()
            ->json('stations');

        // The nearest first, with the brand first in its name and the country as the form has it.
        $this->assertSame($stations['usual']->id, $nearby[0]['id']);
        $this->assertSame('Total Rivoli Paris', $nearby[0]['label']);
        $this->assertSame('france', $nearby[0]['country']);
        $this->assertSame(1.899, $nearby[0]['price']);
        $this->assertNotContains($stations['far']->id, array_column($nearby, 'id'));

        // Stored with the refueling; without one chosen, as before.
        $this->post(route('refuelings.store'), [
            'date' => '2026-10-24', 'country' => 'france', 'gas_station' => 'Esso Bastille Paris', 'fuel_type' => 'Unleaded 95 (E10)',
            'amount' => 40, 'percentage' => 100, 'unit_price' => 1.799, 'total_price' => 71.96, 'mileage_begin' => 10600, 'mileage_end' => 11300,
            'poi_id' => $stations['cheap']->id, 'latitude' => 48.853, 'longitude' => 2.369,
        ])->assertSessionHasNoErrors();

        $this->assertSame($stations['cheap']->id, (int) $vehicle->refuelings()->newest()->first()->poi_id);
    }

    public function test_the_fuel_advice_knows_the_cheapest_gas_stations_near_home()
    {
        $vehicle = $this->parisian();
        $this->stations();
        FuelPrice::create(['date' => '2026-10-23', 'country' => 'france', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => 1.869]);

        $france = collect(app(FuelAdviceFacts::class)->for($vehicle)['countries'])->firstWhere('country', 'france');

        $this->assertSame(['Esso Bastille', 'BP Opéra', 'Total Rivoli'], array_column($france['cheapest_gas_stations'], 'name'));
        $this->assertSame('Rue de Lyon, Paris', $france['cheapest_gas_stations'][0]['address']);
        $this->assertSame('home', $france['cheapest_gas_stations'][0]['distance_from']);
    }

    public function test_a_nearly_empty_tank_tells_the_cheapest_gas_station_once_per_tank()
    {
        $vehicle = $this->parisian();
        $this->stations();

        $notification = app(LowTank::class)->notifications($vehicle)[0];

        // 13 days at 60 km a day since the tank was full: about 6% left.
        $this->assertStringContainsString('6%', $notification['text']);
        $this->assertStringContainsString('Esso Bastille € 1,799', $notification['text']);
        $this->assertStringContainsString('(10 ct below Total Rivoli)', $notification['text']);
        $this->assertSame('fuel', $notification['push']);

        // Another price the next day: the same notification, not a new one to push.
        app(NotificationCenter::class)->sync($vehicle);
        $first = VehicleNotification::query()->where('key', $notification['key'])->sole()->notified_at;
        StationPrice::query()->where('price', 1.799)->update(['price' => 1.789]);
        Cache::flush();
        $this->travel(1)->day();
        app(NotificationCenter::class)->sync($vehicle->fresh());

        $stored = VehicleNotification::query()->where('key', $notification['key'])->sole();
        $this->assertTrue($stored->notified_at->eq($first));
        $this->assertStringContainsString('1,789', $stored->text);
    }

    public function test_the_name_of_a_chosen_gas_station_says_its_brand_and_place_once()
    {
        $this->assertSame('TotalEnergies Express Hellevoetsluis', RefuelingController::stationLabel('TotalEnergies', 'TotalEnergies Express Hellevoetsluis', 'Hellevoetsluis'));
        $this->assertSame('Shell Hellevoetsluis', RefuelingController::stationLabel('Shell', 'Shell', 'Hellevoetsluis'));
        $this->assertSame('Tango Rotterdam', RefuelingController::stationLabel('Tango', null, 'Rotterdam'));
        $this->assertSame('Gas station', RefuelingController::stationLabel(null, null, null));
    }

    public function test_the_fuel_of_the_cheapest_near_home_can_be_chosen()
    {
        $vehicle = $this->parisian();
        $this->stations();
        $poi = Poi::query()->where('name', 'BP Opéra')->sole();
        StationPrice::query()->insert([
            'country' => 'FR', 'source' => 'PrixCarburants', 'source_id' => (string) $poi->id, 'latitude' => $poi->latitude, 'longitude' => $poi->longitude,
            'fuel' => 'diesel', 'fuel_type' => 'Diesel', 'price' => 1.699, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Euro 95 (E10) to start with.
        $this->assertSame('Unleaded 95 (E10)', app(DashboardService::class)->nearbyStations($vehicle)['selected']);

        $diesel = $this->getJson(route('dashboard.nearby-stations', ['fuel_type' => 'Diesel']))->assertOk()->json();
        $this->assertSame('Diesel', $diesel['selected']);
        $this->assertSame(['BP Opéra'], array_column($diesel['stations'], 'name'));

        $this->getJson(route('dashboard.nearby-stations', ['fuel_type' => 'Kerosine']))->assertUnprocessable();
    }

    public function test_the_dashboard_shows_the_cheapest_near_home_compared_with_the_usual_one()
    {
        $vehicle = $this->parisian();
        $this->stations();

        $data = app(DashboardService::class)->nearbyStations($vehicle);

        $this->assertSame(['name' => 'Total Rivoli', 'price' => 1.899], $data['usual']);
        $this->assertSame('Esso Bastille', $data['stations'][0]['name']);
        $this->assertSame(-0.1, $data['stations'][0]['difference']);
        // 10 ct on 50 liters is € 5, at the € 0,114 a kilometer the refuelings cost: 21 km there and back.
        $this->assertSame(21, $data['stations'][0]['detourKm']);

        $vehicle->user->update(['latitude' => null, 'longitude' => null]);
        $this->assertTrue(app(DashboardService::class)->nearbyStations($vehicle->fresh())['missingHome']);
    }

    public function test_the_predictions_tell_the_next_refueling_at_the_cheapest_nearby()
    {
        $vehicle = $this->parisian();
        $this->stations();

        $refueling = collect(app(TimelineService::class)->predictions($vehicle))
            ->flatMap(fn ($month) => $month['items'])
            ->first(fn ($item) => str_starts_with($item['key'], 'refueling-'));

        // 36 liters at € 1,799.
        $this->assertSame('€ 64,76', $refueling['price']);
        $this->assertSame('Esso Bastille', $refueling['location']);
    }

    public function test_the_prices_near_home_are_kept_by_the_day()
    {
        $this->parisian();
        $stations = $this->stations();

        $this->artisan('trip-planner:record-prices')->assertSuccessful();
        StationPrice::query()->where('price', 1.799)->update(['price' => 1.759]);
        Cache::flush();
        $this->travel(3)->hours();
        $this->artisan('trip-planner:record-prices')->assertSuccessful();

        $cheap = StationPriceHistory::query()->where('poi_id', $stations['cheap']->id)->sole();
        $this->assertSame([1.759, 1.799, 1.759, 2], [$cheap->lowest_price, $cheap->highest_price, $cheap->last_price, $cheap->samples]);
        // Only near home.
        $this->assertSame(0, StationPriceHistory::query()->where('poi_id', $stations['far']->id)->count());
    }
}
