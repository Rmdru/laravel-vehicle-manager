<?php

namespace Tests\Feature;

use App\Models\Finding;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\Product;
use App\Models\Refueling;
use App\Models\Tax;
use App\Models\Vehicle;
use App\Services\ConsumptionDrift;
use App\Services\CostForecast;
use App\Services\DashboardService;
use App\Services\Standstill;
use App\Services\TopUps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What helps keep the vehicle reliable at a low cost: the consumption that
 * drifts, the fluids topped up, a vehicle standing still, and what the
 * vehicle is going to cost.
 */
class ReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-27 12:00:00');
    }

    /**
     * Refuelings a week apart, the latest first, with the given consumptions.
     *
     * @param  list<float>  $consumptions
     */
    private function refuelings(Vehicle $vehicle, array $consumptions, string $latest = '2026-10-25', int $km = 50000): void
    {
        foreach ($consumptions as $index => $consumption) {
            Refueling::factory()->for($vehicle)->create([
                'date' => now()->parse($latest)->subWeeks($index)->toDateString(),
                'fuel_consumption' => $consumption,
                'mileage_begin' => $km - ($index + 1) * 500,
                'mileage_end' => $km - $index * 500,
            ]);
        }
    }

    public function test_a_consumption_clearly_higher_than_before_needs_attention()
    {
        $vehicle = $this->signInWithVehicle();
        $this->refuelings($vehicle, [...array_fill(0, 5, 7.0), ...array_fill(0, 15, 6.0)]);

        $drift = app(ConsumptionDrift::class)->of($vehicle->fresh());
        $this->assertSame(7.0, $drift['recent']);
        $this->assertSame(6.0, $drift['usual']);
        $this->assertFalse($drift['same_season']);

        $notification = collect(app(DashboardService::class)->notifications($vehicle->fresh()))->firstWhere('key', 'consumption_drift');
        $this->assertSame('warning', $notification['type']);
        // 16.7% more, in steps of five.
        $this->assertStringStartsWith('The consumption is 15% higher than usual (7,0 against 6,0 l/100km)', $notification['text']);
    }

    public function test_a_consumption_a_little_higher_needs_no_attention()
    {
        $vehicle = $this->signInWithVehicle();
        $this->refuelings($vehicle, [...array_fill(0, 5, 6.3), ...array_fill(0, 15, 6.0)]);

        $this->assertSame([], app(ConsumptionDrift::class)->notifications($vehicle->fresh()));
    }

    public function test_the_consumption_is_compared_with_the_same_time_of_year_when_known()
    {
        $vehicle = $this->signInWithVehicle();
        // Now and in the same weeks a year ago 7 l/100km: winter, not a drift.
        $this->refuelings($vehicle, array_fill(0, 5, 7.0));
        $this->refuelings($vehicle, array_fill(0, 4, 7.0), latest: '2025-10-25', km: 30000);
        $this->refuelings($vehicle, array_fill(0, 15, 6.0), latest: '2025-08-01', km: 28000);

        $drift = app(ConsumptionDrift::class)->of($vehicle->fresh());

        $this->assertTrue($drift['same_season']);
        $this->assertSame(7.0, $drift['usual']);
        $this->assertSame([], app(ConsumptionDrift::class)->notifications($vehicle->fresh()));
    }

    public function test_the_oil_an_engine_uses_since_the_last_oil_change()
    {
        $vehicle = $this->signInWithVehicle(['mileage_latest' => 110000]);
        Maintenance::factory()->for($vehicle)->create(['date' => '2026-01-10', 'mileage' => 100000, 'tasks' => [['task' => 'Olie verversen', 'price' => 90, 'components' => ['engine_oil']]]]);
        Refueling::factory()->for($vehicle)->create(['date' => '2026-10-27', 'mileage_begin' => 109500, 'mileage_end' => 110000]);
        Product::factory()->for($vehicle)->create(['category' => 'engine_oil', 'quantity' => 1, 'mileage' => 104000, 'date' => '2026-05-01']);
        Product::factory()->for($vehicle)->create(['category' => 'engine_oil', 'quantity' => 2, 'mileage' => 108000, 'date' => '2026-09-01', 'price' => null]);
        // Bought, not topped up: no mileage.
        Product::factory()->for($vehicle)->create(['category' => 'engine_oil', 'quantity' => 5, 'date' => '2026-09-02']);

        $oil = app(TopUps::class)->oilConsumption($vehicle->fresh());

        $this->assertSame(['per_1000_km' => 0.3, 'liters' => 3.0, 'km' => 10000, 'since' => '2026-01-10'], $oil);
        $this->assertSame('info', collect(app(TopUps::class)->notifications($vehicle->fresh()))->firstWhere('key', 'top_ups:engine_oil')['type']);

        Product::factory()->for($vehicle)->create(['category' => 'engine_oil', 'quantity' => 2.5, 'mileage' => 109800, 'date' => '2026-10-20']);

        $this->assertSame('warning', collect(app(TopUps::class)->notifications($vehicle->fresh()))->firstWhere('key', 'top_ups:engine_oil')['type']);
    }

    public function test_coolant_topped_up_again_and_brake_fluid_topped_up_need_attention()
    {
        $vehicle = $this->signInWithVehicle();
        Product::factory()->for($vehicle)->create(['category' => 'coolant', 'quantity' => 0.5, 'mileage' => 1100, 'date' => '2026-08-01']);

        $this->assertSame([], app(TopUps::class)->notifications($vehicle->fresh()));

        Product::factory()->for($vehicle)->create(['category' => 'coolant', 'quantity' => 0.3, 'mileage' => 1500, 'date' => '2026-10-01']);
        Product::factory()->for($vehicle)->create(['category' => 'brake_fluid', 'quantity' => 0.1, 'mileage' => 1500, 'date' => '2026-10-01']);

        $keys = array_column(app(TopUps::class)->notifications($vehicle->fresh()), 'type', 'key');

        $this->assertSame(['top_ups:coolant' => 'warning', 'top_ups:brake_fluid' => 'warning'], $keys);
    }

    public function test_a_product_can_be_a_top_up_without_a_price()
    {
        $vehicle = $this->signInWithVehicle();

        $this->post(route('products.store'), [
            'name' => 'Castrol Edge 5W-30',
            'category' => 'engine_oil',
            'quantity' => 0.75,
            'mileage' => 1200,
            'date' => '2026-10-26',
        ])->assertSessionHasNoErrors();

        $product = $vehicle->products()->sole();
        $this->assertTrue($product->isTopUp());
        $this->assertNull($product->price);
    }

    public function test_a_suspended_vehicle_standing_still_gets_what_it_needs()
    {
        $vehicle = $this->signInWithVehicle(['status' => 'suspended', 'powertrain' => 'electricity', 'rdw_data' => ['vervaldatum_apk_dt' => '2026-09-01T00:00:00.000']]);
        Refueling::factory()->for($vehicle)->create(['date' => '2026-07-01']);
        Insurance::factory()->for($vehicle)->create(['start_date' => '2026-01-01', 'end_date' => null]);

        $keys = array_column(app(Standstill::class)->notifications($vehicle->fresh()), 'type', 'key');

        $this->assertSame([
            'standstill:battery' => 'info',
            'standstill:high_voltage' => 'info',
            'standstill:tires_brakes' => 'info',
            'standstill:mot' => 'warning',
            'standstill:insurance' => 'info',
        ], $keys);

        // On the dashboard too, though the status service has nothing to say about it.
        $this->assertContains('standstill:battery', array_column(app(DashboardService::class)->notifications($vehicle->fresh()), 'key'));
    }

    public function test_a_vehicle_that_is_driven_does_not_stand_still()
    {
        $vehicle = $this->signInWithVehicle();

        $this->assertSame([], app(Standstill::class)->notifications($vehicle));
    }

    public function test_the_forecast_adds_up_what_is_coming_in_the_next_twelve_months()
    {
        $vehicle = $this->signInWithVehicle(['construction_date' => '2018-01-01', 'purchase_date' => '2024-01-01', 'mileage_start' => 60000, 'mileage_latest' => 90000, 'rdw_data' => ['vervaldatum_apk_dt' => '2027-03-01T00:00:00.000']]);
        Insurance::factory()->for($vehicle)->create(['start_date' => '2026-01-01', 'end_date' => null, 'price' => 50]);
        Tax::factory()->for($vehicle)->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'price' => 30]);
        // Brake fluid every 2 years: due in December, € 80 last time.
        Maintenance::factory()->for($vehicle)->create(['date' => '2024-12-01', 'mileage' => 75000, 'garage' => 'Garage Jansen', 'total_price' => 80, 'tasks' => [['task' => 'Remvloeistof', 'price' => 80, 'components' => ['brake_fluid']]]]);
        Finding::factory()->for($vehicle)->create(['title' => 'Schokdempers voor', 'estimated_cost' => 400]);

        $forecast = app(CostForecast::class)->forecast($vehicle->fresh());
        $items = collect($forecast['groups'])->flatMap(fn ($group) => $group['items'])->keyBy('label');

        $this->assertSame(600.0, $items['Insurance']['amount']);
        $this->assertSame(360.0, $items['Road tax']['amount']);
        $this->assertSame(80.0, $items['Brake fluid']['amount']);
        $this->assertSame(50.0, $items['MOT']['amount']);
        $this->assertSame(400.0, $items['Schokdempers voor']['amount']);
        // Components never paid for have no price.
        $this->assertGreaterThan(0, $forecast['unknown']);
        $this->assertEqualsWithDelta($forecast['total'] / 12, $forecast['perMonth'], 0.01);
    }

    public function test_the_repairs_ahead_are_weighed_against_what_the_vehicle_is_worth()
    {
        $vehicle = $this->signInWithVehicle();
        Finding::factory()->for($vehicle)->create(['estimated_cost' => 1200]);
        $forecasts = app(CostForecast::class);

        $this->assertNull($forecasts->repairOrReplace($vehicle, $forecasts->forecast($vehicle)));

        $vehicle->powertrainComparisons()->create(['locale' => 'en', 'current' => ['price_now_eur' => 2000, 'depreciation_per_year_eur' => 400], 'alternatives' => [], 'input_hash' => 'x', 'model' => 'test']);
        $verdict = $forecasts->repairOrReplace($vehicle->fresh(), $forecasts->forecast($vehicle->fresh()));

        $this->assertSame('replace', $verdict['verdict']);
        $this->assertSame(0.6, $verdict['share']);
        $this->assertSame(400.0, $forecasts->forecast($vehicle->fresh())['depreciation']);
    }

    public function test_the_cost_per_km_since_the_purchase()
    {
        $vehicle = $this->signInWithVehicle(['purchase_date' => '2026-01-01', 'mileage_start' => 10000, 'mileage_latest' => 20000, 'purchase_price' => 10000]);
        Refueling::factory()->for($vehicle)->create(['date' => '2026-10-27', 'mileage_begin' => 19500, 'mileage_end' => 20000, 'total_price' => 1000]);
        Maintenance::factory()->for($vehicle)->create(['date' => '2026-06-01', 'mileage' => 15000, 'total_price' => 500]);
        $vehicle->powertrainComparisons()->create(['locale' => 'en', 'current' => ['price_now_eur' => 8000], 'alternatives' => [], 'input_hash' => 'x', 'model' => 'test']);

        $perKm = app(CostForecast::class)->perKm($vehicle->fresh());

        $this->assertSame(10000, $perKm['km']);
        $this->assertSame(1500.0, $perKm['costs']);
        $this->assertSame(0.15, $perKm['perKm']);
        $this->assertSame(2000.0, $perKm['depreciation']);
        $this->assertSame(0.35, $perKm['perKmWithDepreciation']);
        $this->assertSame(2026, $perKm['years'][0]['year']);
    }

    public function test_the_cost_forecast_page_shows_the_prices_paid_by_component_and_by_garage()
    {
        $vehicle = $this->signInWithVehicle();
        Maintenance::factory()->for($vehicle)->create(['date' => '2026-03-01', 'mileage' => 1100, 'garage' => 'Garage Jansen', 'total_price' => 300, 'tasks' => [['task' => 'Remblokken en schijven voor', 'price' => 300, 'components' => ['front_brake_pads', 'front_brake_discs']]]]);
        Maintenance::factory()->for($vehicle)->create(['date' => '2025-03-01', 'mileage' => 1050, 'garage' => 'garage jansen ', 'total_price' => 100]);

        $this->get(route('cost-forecast'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CostForecast')
                // Shared by the two components it was for.
                ->where('prices', fn ($prices) => collect($prices)->firstWhere('key', 'front_brake_pads')['prices'][0]['price'] === '€ 150,00')
                ->where('garages.0.garage', 'Garage Jansen')
                ->where('garages.0.visits', 2)
                ->where('garages.0.average', '€ 200'));

        $this->get(route('maintenance-plan'))
            ->assertInertia(fn (Assert $page) => $page->where('components', fn ($components) => collect($components)->firstWhere('key', 'front_brake_discs')['prices'][0]['garage'] === 'Garage Jansen'));
    }
}
