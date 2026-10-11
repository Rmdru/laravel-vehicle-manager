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
    }

    public function test_cached_statistics_are_cleared_when_a_refueling_is_created()
    {
        $vehicle = Vehicle::factory()->create();

        Cache::put('vehicle:'.$vehicle->id.':some-statistic', 123.45, now()->addHour());

        Refueling::factory()->for($vehicle)->create(['charge_time' => null]);

        $this->assertFalse(Cache::has('vehicle:'.$vehicle->id.':some-statistic'));
    }
}
