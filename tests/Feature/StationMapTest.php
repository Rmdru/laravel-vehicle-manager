<?php

namespace Tests\Feature;

use App\Jobs\FetchPoiTiles;
use App\Models\FuelPrice;
use App\Models\Poi;
use App\Models\PoiTile;
use App\Models\Refueling;
use App\Models\StationPrice;
use App\Services\TripPlanner\FuelPrices;
use App\Services\TripPlanner\Geo;
use App\Services\TripPlanner\PoiRepository;
use App\Services\TripPlanner\Prices\StoredPrices;
use App\Services\TripPlanner\VehicleRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StationMapTest extends TestCase
{
    use RefreshDatabase;

    private int $osm = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-07 12:00:00');
        Http::swap(new Factory);
        // The averages of the ANWB, when the app has no price of a country itself.
        Http::fake(['www.anwb.nl/*' => Http::response('', 404)]);
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function poi(string $kind, float $latitude, float $longitude, array $tags = [], ?string $name = null, ?string $brand = null): Poi
    {
        return Poi::create([
            'osm_type' => 'n',
            'osm_id' => $this->osm++,
            'kind' => $kind,
            'name' => $name,
            'brand' => $brand,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'tags' => $tags,
            'tile' => Geo::tile($latitude, $longitude),
        ]);
    }

    /**
     * The tiles of an area as fetched, so that nothing is fetched for it.
     */
    private function fetched(float $south, float $west, float $north, float $east): void
    {
        $size = (float) config('trip_planner.tile_size');

        for ($lat = $south - $size; $lat <= $north + $size; $lat += $size) {
            for ($lon = $west - $size; $lon <= $east + $size; $lon += $size) {
                PoiTile::updateOrCreate(['tile' => Geo::tile($lat, $lon)], ['fetched_at' => now(), 'version' => config('trip_planner.tile_version')]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function data(array $parameters): array
    {
        return $this->getJson(route('stations.data', $parameters))->assertOk()->json();
    }

    public function test_a_charging_station_without_a_fast_charger_is_a_slow_one()
    {
        $this->assertSame(Poi::CHARGER, Poi::kindOf(['amenity' => 'charging_station', 'socket:type2_combo' => '2']));
        $this->assertSame(Poi::SLOW_CHARGER, Poi::kindOf(['amenity' => 'charging_station', 'socket:type2' => '2']));

        $charger = $this->poi(Poi::SLOW_CHARGER, 52.0, 5.0, ['socket:type2' => '2', 'socket:type2:output' => '22 kW']);
        $this->assertSame(['type2'], $charger->connectors());
        $this->assertSame(22.0, $charger->maxPower());
    }

    public function test_a_tile_of_an_older_version_is_fetched_again()
    {
        PoiTile::create(['tile' => '1:1', 'fetched_at' => now(), 'version' => 1]);
        PoiTile::create(['tile' => '1:2', 'fetched_at' => now(), 'version' => config('trip_planner.tile_version')]);

        $this->assertSame(['1:1', '1:3'], app(PoiRepository::class)->missing(['1:1', '1:2', '1:3']));
    }

    public function test_the_prices_of_all_gas_stations_are_imported_with_self_service_and_served_apart()
    {
        config(['trip_planner.prices.uk_feeds' => []]);
        Http::fake([
            'data.economie.gouv.fr/*/exports/json*' => Http::response([
                ['id' => 75001, 'geom' => ['lat' => 48.86, 'lon' => 2.35], 'ville' => 'Paris', 'e10_prix' => 1.799, 'e10_maj' => '2026-11-07T08:00:00+01:00', 'gazole_prix' => 1.689],
            ]),
            'sedeaplicaciones.minetur.gob.es/*' => Http::response(['ListaEESSPrecio' => [
                ['IDEESS' => '1', 'Latitud' => '40,41', 'Longitud (WGS84)' => '-3,70', 'Rótulo' => 'REPSOL', 'Localidad' => 'MADRID', 'Tipo Venta' => 'P', 'Precio Gasolina 95 E5' => '1,549'],
                ['IDEESS' => '2', 'Latitud' => '40,42', 'Longitud (WGS84)' => '-3,71', 'Tipo Venta' => 'R', 'Precio Gasolina 95 E5' => '1,299'],
            ]]),
            'www.mimit.gov.it/*/anagrafica_impianti_attivi.csv' => Http::response("Estrazione del 2026-11-07\nidImpianto|Gestore|Bandiera|Tipo Impianto|Nome Impianto|Indirizzo|Comune|Provincia|Latitudine|Longitudine\n5|X|Eni|Stradale|Eni Roma|Via 1|ROMA|RM|41.9|12.5\n"),
            'www.mimit.gov.it/*/prezzo_alle_8.csv' => Http::response("Estrazione del 2026-11-07\nidImpianto|descCarburante|prezzo|isSelf|dtComu\n5|Benzina|1.779|1|07/11/2026 07:30:00\n5|Benzina|1.959|0|07/11/2026 07:30:00\n5|Blue Super|1.899|1|07/11/2026 07:30:00\n"),
            'www.ecb.europa.eu/*' => Http::response("<Cube currency='GBP' rate='0.85'/>"),
        ]);

        $this->artisan('trip-planner:import-prices')->assertSuccessful();

        $this->assertSame(['FR' => 2, 'ES' => 1, 'IT' => 2], StationPrice::query()->get()->countBy('country')->all());
        $this->assertSame(['self' => 1.779, 'served' => 1.959], StationPrice::where('country', 'IT')->pluck('price', 'service')->all());
        $this->assertSame('2026-11-07 07:30:00', StationPrice::where('country', 'IT')->first()->reported_at->toDateTimeString());

        // A source that fails keeps the prices it had.
        Http::fake(['www.mimit.gov.it/*' => Http::response('', 500)]);
        $this->artisan('trip-planner:import-prices', ['--country' => ['IT']])->assertSuccessful();
        $this->assertSame(2, StationPrice::where('country', 'IT')->count());
    }

    public function test_the_trip_planner_has_the_served_price_of_italy_too()
    {
        StationPrice::query()->insert([
            ['country' => 'IT', 'source' => 'Mimit', 'source_id' => '5', 'latitude' => 41.9, 'longitude' => 12.5, 'fuel' => 'e5', 'fuel_type' => 'Unleaded 95 (E5)', 'price' => 1.779, 'service' => 'self', 'created_at' => now(), 'updated_at' => now()],
            ['country' => 'IT', 'source' => 'Mimit', 'source_id' => '5', 'latitude' => 41.9, 'longitude' => 12.5, 'fuel' => 'e5', 'fuel_type' => 'Unleaded 95 (E5)', 'price' => 1.959, 'service' => 'served', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame([[
            'latitude' => 41.9,
            'longitude' => 12.5,
            'price' => 1.779,
            'updated_at' => null,
            'fuel_type' => 'Unleaded 95 (E5)',
            'served_price' => 1.959,
        ]], (new StoredPrices('IT'))->near(41.91, 12.5, 'e10'));

        $price = app(FuelPrices::class)->of(['poi_id' => 1, 'country' => 'ITA', 'latitude' => 41.9, 'longitude' => 12.5], new VehicleRange(tank: 50, consumption: 6, fromRefuelings: false, fuelType: 'Unleaded 95 (E5)'));
        $this->assertSame(1.959, $price['served_price']);
    }

    public function test_zoomed_out_the_stations_are_clusters_by_kind()
    {
        $this->signInWithVehicle();
        $this->poi(Poi::GAS_STATION, 52.01, 5.01);
        $this->poi(Poi::GAS_STATION, 52.02, 5.02);
        $this->poi(Poi::CHARGER, 52.03, 5.03);
        $this->poi(Poi::SLOW_CHARGER, 52.04, 5.04);
        // Outside the map.
        $this->poi(Poi::GAS_STATION, 48.0, 2.0);

        $data = $this->data(['south' => 50, 'west' => 3, 'north' => 54, 'east' => 7, 'zoom' => 7, 'kinds' => ['fuel', 'fast']]);

        $this->assertSame('clusters', $data['mode']);
        $this->assertSame(3, array_sum(array_column($data['clusters'], 'count')));
        $counts = array_merge_recursive(...array_column($data['clusters'], 'counts'));
        ksort($counts);
        $this->assertSame(['fast' => 1, 'fuel' => 2], $counts);
    }

    public function test_zoomed_in_the_stations_come_with_their_prices_and_filters()
    {
        $vehicle = $this->signInWithVehicle();
        $this->fetched(48.85, 2.33, 48.87, 2.37);
        $paris = $this->poi(Poi::GAS_STATION, 48.86, 2.35, ['fuel:diesel' => 'yes', 'fuel:e10' => 'yes'], 'Total Rivoli', 'Total');
        $this->poi(Poi::GAS_STATION, 48.861, 2.351, ['fuel:diesel' => 'no', 'fuel:lpg' => 'yes'], 'LPG only');
        $this->poi(Poi::CHARGER, 48.862, 2.352, ['socket:type2_combo' => '4', 'socket:type2_combo:output' => '150 kW']);
        $this->poi(Poi::SLOW_CHARGER, 48.863, 2.353, ['socket:type2' => '2']);
        StationPrice::query()->insert([
            ['country' => 'FR', 'source' => 'PrixCarburants', 'source_id' => '75001', 'latitude' => 48.8601, 'longitude' => 2.3501, 'fuel' => 'diesel', 'fuel_type' => 'Diesel', 'price' => 1.689, 'service' => null, 'created_at' => now(), 'updated_at' => now()],
            ['country' => 'FR', 'source' => 'PrixCarburants', 'source_id' => '75001', 'latitude' => 48.8601, 'longitude' => 2.3501, 'fuel' => 'e10', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => 1.799, 'service' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Refueling::factory()->for($vehicle)->create(['gas_station' => 'Total Rivoli Paris', 'unit_price' => 1.749, 'fuel_type' => 'Diesel', 'date' => '2026-10-01']);

        $data = $this->data(['south' => 48.85, 'west' => 2.33, 'north' => 48.87, 'east' => 2.37, 'zoom' => 14, 'kinds' => ['fuel', 'fast'], 'fuels' => ['Diesel']]);

        $this->assertSame('stations', $data['mode']);
        $this->assertFalse($data['loading']);
        // Not the slow charger, nor the gas station without diesel.
        $this->assertEqualsCanonicalizing(['fuel', 'fast'], array_column($data['stations'], 'kind'));
        $station = collect($data['stations'])->firstWhere('id', $paris->id);
        // Only the diesel price, as filtered on.
        $this->assertSame([['fuel_type' => 'Diesel', 'price' => 1.689, 'service' => null, 'served_price' => null, 'updated_at' => null]], $station['prices']);
        $this->assertSame(['price' => 1.749, 'fuel_type' => 'Diesel', 'date' => '2026-10-01'], $station['own']);
        $this->assertSame(150, (int) collect($data['stations'])->firstWhere('kind', 'fast')['power']);
    }

    public function test_without_prices_of_its_own_a_gas_station_has_the_average_of_the_country()
    {
        $this->signInWithVehicle();
        $this->fetched(52.0, 4.9, 52.02, 4.92);
        $this->poi(Poi::GAS_STATION, 52.01, 4.91, ['addr:country' => 'NL']);
        FuelPrice::create(['date' => today(), 'country' => 'netherlands', 'fuel_type' => 'Diesel', 'price' => 1.799]);

        $data = $this->data(['south' => 52.0, 'west' => 4.9, 'north' => 52.02, 'east' => 4.92, 'zoom' => 14, 'kinds' => ['fuel'], 'fuels' => ['Diesel']]);

        $this->assertSame([], $data['stations'][0]['prices']);
        // No source of prices per gas station in the Netherlands.
        $this->assertFalse($data['stations'][0]['live']);
        $this->assertSame(['NL' => [['fuel_type' => 'Diesel', 'price' => 1.799]]], $data['averages']);
    }

    public function test_the_prices_of_a_source_asked_per_area_come_apart()
    {
        config(['tankerkoenig.api_key' => 'key']);
        $this->signInWithVehicle();
        $this->fetched(52.5, 13.39, 52.52, 13.41);
        $berlin = $this->poi(Poi::GAS_STATION, 52.51, 13.40, ['addr:country' => 'DE']);
        Http::fake(['creativecommons.tankerkoenig.de/*' => Http::response(['stations' => [
            ['lat' => 52.5101, 'lng' => 13.4001, 'e5' => 1.859, 'e10' => 1.799, 'diesel' => 1.699],
        ]])]);
        $bounds = ['south' => 52.5, 'west' => 13.39, 'north' => 52.52, 'east' => 13.41, 'zoom' => 13];

        $this->assertTrue($this->data([...$bounds, 'kinds' => ['fuel']])['stations'][0]['live']);

        $prices = $this->getJson(route('stations.prices', [...$bounds, 'fuels' => ['Diesel']]))->assertOk()->json('prices');
        $this->assertSame(1.699, $prices[$berlin->id][0]['price']);

        // Zoomed out not asked: too many areas at once.
        $this->assertSame([], $this->getJson(route('stations.prices', [...$bounds, 'zoom' => 10]))->json('prices'));
    }

    public function test_with_the_anwb_on_a_dutch_gas_station_has_its_prices_and_the_primary_fuel_always()
    {
        config(['trip_planner.prices.anwb.enabled' => true]);
        $this->signInWithVehicle();
        $this->fetched(51.83, 4.13, 51.85, 4.15);
        $pump = $this->poi(Poi::GAS_STATION, 51.8387, 4.1341, ['addr:country' => 'NL'], 'Berkman');
        Http::fake(['api.anwb.nl/*' => Http::response(['value' => [[
            'coordinates' => ['latitude' => 51.838695, 'longitude' => 4.134066],
            'prices' => [
                ['fuelName' => 'Euro 95 (E10)', 'fuelType' => 'EURO95', 'value' => 2.099, 'currency' => 'EUR'],
                ['fuelName' => 'Diesel (B7)', 'fuelType' => 'DIESEL', 'value' => 2.279, 'currency' => 'EUR'],
                ['fuelName' => 'Autogas', 'fuelType' => 'AUTOGAS', 'value' => 1.099, 'currency' => 'EUR'],
            ],
        ]]])]);
        $bounds = ['south' => 51.83, 'west' => 4.13, 'north' => 51.85, 'east' => 4.15, 'zoom' => 11];

        $this->assertTrue($this->data([...$bounds, 'kinds' => ['fuel']])['stations'][0]['live']);

        // Filtered on diesel, with LPG in the badges: both.
        $prices = $this->getJson(route('stations.prices', [...$bounds, 'fuels' => ['Diesel'], 'primary' => 'LPG']))->json('prices');
        $this->assertEqualsCanonicalizing(['Diesel', 'LPG'], array_column($prices[$pump->id], 'fuel_type'));
        // All fuels of an area in one request.
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'api.anwb.nl')));
    }

    public function test_an_area_that_is_not_there_yet_is_fetched_in_the_background()
    {
        Queue::fake();
        $this->signInWithVehicle();
        $bounds = ['south' => 45.0, 'west' => 9.0, 'north' => 45.3, 'east' => 9.4, 'zoom' => 10, 'kinds' => ['fuel']];

        $this->assertTrue($this->data($bounds)['loading']);
        // Once at a time.
        $this->data($bounds);
        Queue::assertPushed(FetchPoiTiles::class, 1);

        // Moved a little: only the tiles that were not asked for yet.
        $first = Queue::pushed(FetchPoiTiles::class)->first()->tiles;
        $this->data([...$bounds, 'east' => 9.65]);
        $second = Queue::pushed(FetchPoiTiles::class)->last()->tiles;
        $this->assertNotEmpty($second);
        $this->assertSame([], array_intersect($first, $second));

        // Zoomed out too far: not fetched.
        $this->assertFalse($this->data([...$bounds, 'south' => 40, 'north' => 50, 'west' => 0, 'east' => 15, 'zoom' => 6])['loading']);
    }

    public function test_the_map_page_shows_with_the_fuels_of_the_vehicle()
    {
        $this->signInWithVehicle(['fuel_types' => ['Diesel'], 'powertrain' => 'diesel']);

        $this->get(route('stations'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stations')
                ->where('vehicleFuelTypes', ['Diesel'])
                ->where('electric', false)
                ->where('connectors', ['ccs', 'chademo', 'type2'])
                ->etc());
    }
}
