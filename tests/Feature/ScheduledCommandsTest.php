<?php

namespace Tests\Feature;

use App\Enums\VehicleStatus;
use App\Models\FuelDetourAggregate;
use App\Models\FuelPrice;
use App\Models\Refueling;
use App\Models\Vehicle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduledCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Start without the catch-all fake of the base test case: these tests define the responses.
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public function test_the_imports_are_scheduled_daily()
    {
        $events = collect(app(Schedule::class)->events());

        $vehicleData = $events->first(fn ($event) => str_contains($event->command, 'import:vehicle-data'));
        $fuelPrices = $events->first(fn ($event) => str_contains($event->command, 'import:fuel-prices'));

        $this->assertSame('0 0 * * *', $vehicleData?->expression);
        $this->assertSame('0 1 * * *', $fuelPrices?->expression);
    }

    public function test_importing_vehicle_data_stores_the_rdw_data_and_status()
    {
        $vehicle = Vehicle::factory()->create([
            'license_plate' => 'AB-123-CD',
            'powertrain' => 'diesel',
            'tank_capacity' => 50,
            'status' => VehicleStatus::Drivable->value,
        ]);

        Http::fake([
            'https://opendata.rdw.nl/resource/m9d7-ebf2.json*' => Http::response([['kenteken' => 'AB123CD', 'wacht_op_keuren' => 'In afwachting']]),
            'https://opendata.rdw.nl/resource/t49b-isb7.json*' => Http::response([['referentiecode_rdw' => 'RC123']]),
            'https://opendata.rdw.nl/resource/j9yg-7rg9.json*' => Http::response([['description' => 'Test recall detail']]),
        ]);

        $this->artisan('import:vehicle-data')->assertExitCode(0);

        $vehicle->refresh();

        $this->assertSame('In afwachting', $vehicle->rdw_data['wacht_op_keuren']);
        $this->assertSame('RC123', $vehicle->rdw_data['open_recalls'][0]['referentiecode_rdw']);
        $this->assertSame(VehicleStatus::Wok->value, $vehicle->status);
    }

    /**
     * @return array<string, array{0: VehicleStatus, 1: array<string, string>, 2: VehicleStatus}>
     */
    public static function rdwStatuses(): array
    {
        return [
            'expired MOT' => [VehicleStatus::Drivable, ['vervaldatum_apk_dt' => '2026-01-31T00:00:00.000'], VehicleStatus::Apk],
            'MOT expires today' => [VehicleStatus::Drivable, ['vervaldatum_apk_dt' => '2026-03-01T00:00:00.000'], VehicleStatus::Drivable],
            'WOK goes before an expired MOT' => [VehicleStatus::Apk, ['wacht_op_keuren' => 'In afwachting', 'vervaldatum_apk_dt' => '2026-01-31T00:00:00.000'], VehicleStatus::Wok],
            'WOK lifted' => [VehicleStatus::Wok, ['wacht_op_keuren' => 'Geen verstrekking in Open Data', 'vervaldatum_apk_dt' => '2027-01-31T00:00:00.000'], VehicleStatus::Drivable],
            'MOT renewed' => [VehicleStatus::Apk, ['vervaldatum_apk_dt' => '2027-01-31T00:00:00.000'], VehicleStatus::Drivable],
            'sold is left alone' => [VehicleStatus::Sold, ['wacht_op_keuren' => 'In afwachting'], VehicleStatus::Sold],
            'stolen is left alone' => [VehicleStatus::Stolen, ['vervaldatum_apk_dt' => '2026-01-31T00:00:00.000'], VehicleStatus::Stolen],
            'suspended is left alone' => [VehicleStatus::Suspended, [], VehicleStatus::Suspended],
        ];
    }

    #[DataProvider('rdwStatuses')]
    public function test_importing_vehicle_data_updates_the_status_the_rdw_manages(VehicleStatus $current, array $rdwData, VehicleStatus $expected)
    {
        $this->travelTo('2026-03-01 12:00:00');

        $vehicle = Vehicle::factory()->create(['license_plate' => 'AB-123-CD', 'status' => $current->value]);

        Http::fake([
            'https://opendata.rdw.nl/resource/m9d7-ebf2.json*' => Http::response([['kenteken' => 'AB123CD', ...$rdwData]]),
            'https://opendata.rdw.nl/resource/t49b-isb7.json*' => Http::response([]),
        ]);

        $this->artisan('import:vehicle-data')->assertExitCode(0);

        $this->assertSame($expected->value, $vehicle->refresh()->status);
    }

    public function test_importing_fuel_prices_stores_dutch_belgian_luxembourg_and_german_prices()
    {
        $vehicle = Vehicle::factory()->create(['powertrain' => 'diesel', 'tank_capacity' => 50]);

        Http::fake([
            'https://opendata.cbs.nl/ODataApi/odata/80416ENG/TypedDataSet*' => Http::response([
                'value' => [['Periods' => '2026-05-26T00:00:00', 'Euro95_1' => 1.65, 'Diesel_2' => 1.45, 'LPG_3' => 0.89]],
            ]),
            'https://carbu.com/belgie/index.php/officieleprijs*' => Http::response(
                '<table class="prix-officiel"><tbody>'
                .'<tr><td>Super 95 (E10)</td><td class="price">€ 1,74 /l</td></tr>'
                .'<tr><td>Super 98 (E5)</td><td class="price">€ 1,84 /l</td></tr>'
                .'<tr><td>Diesel (B7)</td><td class="price">€ 1,55 /l</td></tr>'
                .'<tr><td>LPG</td><td class="price">€ 0,82 /l</td></tr>'
                .'</tbody></table>',
            ),
            'https://carbu.com/luxembourg/index.php/prixmaximum*' => Http::response(
                '<table class="table prix-officiel"><tbody>'
                .'<tr><td><span>Diesel</span></td><td class="price">1,452 &euro;/l</td></tr>'
                .'<tr><td><span>Super 95</span></td><td class="price">1,473 &euro;/l</td></tr>'
                .'<tr><td><span>Super 98</span></td><td class="price">1,580 &euro;/l</td></tr>'
                .'<tr><td><span>LPG</span></td><td class="price">0,919 &euro;/l</td></tr>'
                .'</tbody></table>',
            ),
            'https://www.anwb.nl/vakantie/reisvoorbereiding/brandstofprijzen-europa*' => Http::response(
                '<html><body><script id="__NEXT_DATA__" type="application/json">'.json_encode(['props' => ['pageProps' => ['loaderData' => ['3f1e' => ['countries' => [
                    ['iso3CountryCode' => 'BEL', 'country' => 'België', 'fuelPrices' => [['fuelTypeCode' => 'EURO98', 'averagePrice' => 2.111]]],
                    ['iso3CountryCode' => 'NLD', 'country' => 'Nederland', 'fuelPrices' => [
                        ['fuelType' => 'Euro 95 (E10)', 'fuelTypeCode' => 'EURO95', 'averagePrice' => 2.427],
                        ['fuelType' => 'Super Plus 98 (E5)', 'fuelTypeCode' => 'EURO98', 'averagePrice' => 2.728],
                    ]],
                ]]]]]]).'</script></body></html>',
            ),
            'https://creativecommons.tankerkoenig.de/api/v4/stats*' => Http::response([
                'timestamp' => '2026-05-26T10:00:00Z',
                'E10' => ['median' => 1.62],
                'E5' => ['median' => 1.78],
                'Diesel' => ['median' => 1.48],
            ]),
        ]);

        $this->artisan('import:fuel-prices')->assertExitCode(0);

        $this->assertTrue(FuelPrice::where('country', 'netherlands')->where('fuel_type', 'Unleaded 95 (E10)')->exists());
        $this->assertEquals(1.48, FuelPrice::where('country', 'germany')->where('fuel_type', 'Diesel')->first()->price);
        $this->assertSame(4, FuelPrice::where('country', 'luxembourg')->count());
        $this->assertEquals(1.473, FuelPrice::where('country', 'luxembourg')->where('fuel_type', 'Unleaded 95 (E10)')->first()->price);
        $this->assertEquals(1.452, FuelPrice::where('country', 'luxembourg')->where('fuel_type', 'Diesel')->first()->price);
        $this->assertTrue(FuelDetourAggregate::where('vehicle_id', $vehicle->id)->exists());

        // Only what the CBS lacks comes from the ANWB, as the price of today.
        $superPlus = FuelPrice::where('country', 'netherlands')->where('fuel_type', 'Super Plus (E5)')->sole();
        $this->assertEquals(2.728, $superPlus->price);
        $this->assertSame(today()->toDateString(), $superPlus->date->toDateString());
        $this->assertSame('2026-05-26', FuelPrice::where('country', 'netherlands')->where('fuel_type', 'Unleaded 95 (E10)')->sole()->date->toDateString());
    }

    public function test_the_fuel_prices_are_imported_without_the_anwb_when_its_page_cannot_be_read()
    {
        Http::fake([
            'https://opendata.cbs.nl/*' => Http::response(['value' => [['Periods' => '2026-05-26T00:00:00', 'Euro95_1' => 1.65, 'Diesel_2' => 1.45, 'LPG_3' => 0.89]]]),
            'https://www.anwb.nl/*' => Http::response('<html><body>Nothing to see</body></html>'),
            '*' => Http::response('', 500),
        ]);

        $this->artisan('import:fuel-prices')->assertExitCode(0);

        $this->assertSame(3, FuelPrice::where('country', 'netherlands')->count());
    }

    public function test_cached_statistics_are_cleared_when_a_refueling_is_created()
    {
        $vehicle = Vehicle::factory()->create();

        Cache::put('vehicle:'.$vehicle->id.':some-statistic', 123.45, now()->addHour());

        Refueling::factory()->for($vehicle)->create(['charge_time' => null]);

        $this->assertFalse(Cache::has('vehicle:'.$vehicle->id.':some-statistic'));
    }
}
