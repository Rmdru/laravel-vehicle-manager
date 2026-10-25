<?php

namespace Tests\Feature;

use App\Models\Accident;
use App\Models\EnvironmentalSticker;
use App\Models\Ferry;
use App\Models\Finding;
use App\Models\Fine;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\Parking;
use App\Models\Product;
use App\Models\Reconditioning;
use App\Models\Refueling;
use App\Models\Service;
use App\Models\Tax;
use App\Models\Toll;
use App\Models\Vehicle;
use App\Models\Vignette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The behaviour every type of vehicle record shares, and what is specific to some.
 */
class VehicleRecordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function records(): array
    {
        return [
            'maintenances' => ['maintenances', Maintenance::class],
            'findings' => ['findings', Finding::class],
            'reconditionings' => ['reconditionings', Reconditioning::class],
            'accidents' => ['accidents', Accident::class],
            'products' => ['products', Product::class],
            'refuelings' => ['refuelings', Refueling::class],
            'insurances' => ['insurances', Insurance::class],
            'taxes' => ['taxes', Tax::class],
            'parking' => ['parking', Parking::class],
            'toll' => ['toll', Toll::class],
            'fines' => ['fines', Fine::class],
            'vignettes' => ['vignettes', Vignette::class],
            'environmental stickers' => ['environmental-stickers', EnvironmentalSticker::class],
            'services' => ['services', Service::class],
            'ferries' => ['ferries', Ferry::class],
        ];
    }

    /**
     * The records, by their route alone: for the tests that need no model.
     *
     * @return array<string, array{string}>
     */
    public static function routes(): array
    {
        return array_map(fn (array $record) => [$record[0]], self::records());
    }

    #[DataProvider('routes')]
    public function test_guests_cannot_see_the_records(string $route)
    {
        $this->get(route("{$route}.index"))->assertRedirect(route('login'));
    }

    #[DataProvider('records')]
    public function test_the_index_lists_only_the_records_of_the_current_vehicle(string $route, string $model)
    {
        $vehicle = $this->signInWithVehicle();
        $record = $model::factory()->for($vehicle)->create();
        $model::factory()->for(Vehicle::factory())->create();

        $this->get(route("{$route}.index"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Crud/Index')
                ->has('table.data', 1)
                ->where('table.data.0.id', $record->id)
                ->where('baseUrl', route("{$route}.index"))
                ->has('title')
                ->has('summary'));
    }

    #[DataProvider('records')]
    public function test_the_create_and_edit_forms_can_be_opened(string $route, string $model)
    {
        $vehicle = $this->signInWithVehicle();
        $record = $model::factory()->for($vehicle)->create();

        $this->get(route("{$route}.create"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Crud/Form')
                ->where('form.method', 'POST')
                ->where('form.action', route("{$route}.store")));

        $this->get(route("{$route}.edit", $record->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Crud/Form')
                ->where('form.method', 'PATCH')
                ->where('destroyUrl', route("{$route}.destroy", $record->id))
                ->has('form.defaults'));
    }

    #[DataProvider('routes')]
    public function test_storing_validates_the_input(string $route)
    {
        $this->signInWithVehicle();

        $this->post(route("{$route}.store"), [])->assertSessionHasErrors();
    }

    #[DataProvider('records')]
    public function test_a_record_can_be_duplicated_and_deleted(string $route, string $model)
    {
        $vehicle = $this->signInWithVehicle();
        $record = $model::factory()->for($vehicle)->create();

        $this->post(route("{$route}.duplicate", $record->id))->assertRedirect(route("{$route}.index"));

        $this->assertSame(2, $model::where('vehicle_id', $vehicle->id)->count());

        $this->delete(route("{$route}.destroy", $record->id))->assertRedirect(route("{$route}.index"));

        $this->assertNull($model::find($record->id));
        $this->assertSame(1, $model::where('vehicle_id', $vehicle->id)->count());
    }

    #[DataProvider('records')]
    public function test_records_of_other_vehicles_are_not_accessible(string $route, string $model)
    {
        $this->signInWithVehicle();
        $record = $model::factory()->for(Vehicle::factory())->create();

        $this->get(route("{$route}.edit", $record->id))->assertNotFound();
        $this->patch(route("{$route}.update", $record->id), [])->assertNotFound();
        $this->post(route("{$route}.duplicate", $record->id))->assertNotFound();
        $this->delete(route("{$route}.destroy", $record->id))->assertNotFound();

        $this->assertNotNull($model::find($record->id));
    }

    public function test_the_index_can_be_filtered_by_date()
    {
        $vehicle = $this->signInWithVehicle();
        $recent = Product::factory()->for($vehicle)->create(['date' => '2026-06-15']);
        Product::factory()->for($vehicle)->create(['date' => '2025-01-01']);

        $this->get(route('products.index', ['date_from' => '2026-01-01', 'date_until' => '2026-12-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.data', 1)
                ->where('table.data.0.id', $recent->id)
                ->where('filters.date_from', '2026-01-01'));
    }

    public function test_the_index_can_be_filtered_and_the_summary_follows()
    {
        $vehicle = $this->signInWithVehicle();
        $street = Parking::factory()->for($vehicle)->create(['type' => 'street', 'price' => 4]);
        Parking::factory()->for($vehicle)->create(['type' => 'garage', 'price' => 20]);

        $this->get(route('parking.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.data', 2)
                ->where('table.filters.0.key', 'type')
                // The "no filter" entry and the two types in use.
                ->has('table.filters.0.options', 3)
                ->where('summary.0.value', '€ 12,00'));

        $this->get(route('parking.index', ['parking_filter' => ['type' => 'street']]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.data', 1)
                ->where('table.data.0.id', $street->id)
                ->where('summary.0.value', '€ 4,00'));
    }

    public function test_the_index_summarizes_the_prices()
    {
        $vehicle = $this->signInWithVehicle();
        Product::factory()->for($vehicle)->create(['price' => 10]);
        Product::factory()->for($vehicle)->create(['price' => 30]);

        $this->get(route('products.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.0.value', '€ 20,00')
                ->where('summary.1.value', '€ 10,00 - € 30,00'));
    }

    public function test_a_product_can_be_created_and_updated()
    {
        $vehicle = $this->signInWithVehicle();

        $this->post(route('products.store'), ['name' => 'Engine oil', 'price' => '24.95', 'date' => '2026-03-01'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('products.index'));

        $product = $vehicle->products()->sole();
        $this->assertSame('Engine oil', $product->name);

        $this->patch(route('products.update', $product->id), ['name' => 'Coolant', 'price' => '12', 'date' => '2026-03-02'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Coolant', $product->refresh()->name);
        $this->assertSame('2026-03-02', $product->date->format('Y-m-d'));
    }

    public function test_storing_a_refueling_calculates_the_consumption_and_updates_the_mileage()
    {
        $vehicle = $this->signInWithVehicle();

        $this->post(route('refuelings.store'), [
            'date' => '2026-03-01',
            'country' => 'netherlands',
            'gas_station' => 'Shell Utrecht',
            'fuel_type' => 'Unleaded 95 (E10)',
            'amount' => 40,
            'percentage' => 100,
            'unit_price' => 2,
            'total_price' => 80,
            'mileage_begin' => 1000,
            'mileage_end' => 1500,
            'climate_control' => ['airco'],
            'routes' => ['motorway', 'city'],
            'service_by_attendant' => '0',
        ])->assertSessionHasNoErrors()->assertRedirect(route('refuelings.index'));

        $refueling = $vehicle->refuelings()->sole();

        $this->assertEquals(8.0, $refueling->fuel_consumption);
        $this->assertEquals(0.16, $refueling->costs_per_kilometer);
        $this->assertSame(['motorway', 'city'], $refueling->routes);
        $this->assertEquals(1500, $vehicle->refresh()->mileage_latest);

        $this->get(route('refuelings.show', $refueling->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Crud/Show')
                ->where('sections.1.items.1.value', '40,00 l'));
    }

    public function test_the_consumption_accounts_for_a_tank_that_was_not_filled_up()
    {
        // A tank of 50 liters.
        $vehicle = $this->signInWithVehicle();

        $input = fn (int $begin, float $amount, int $percentage, float $price) => [
            'date' => '2026-03-01',
            'gas_station' => 'Shell Utrecht',
            'fuel_type' => 'Unleaded 95 (E10)',
            'amount' => $amount,
            'percentage' => $percentage,
            'unit_price' => 2,
            'total_price' => $price,
            'mileage_begin' => $begin,
            'mileage_end' => $begin + 500,
        ];

        $this->post(route('refuelings.store'), $input(1000, 40, 100, 80))->assertSessionHasNoErrors();
        $this->post(route('refuelings.store'), $input(1500, 20, 60, 40))->assertSessionHasNoErrors();
        $this->post(route('refuelings.store'), $input(2000, 45, 100, 90))->assertSessionHasNoErrors();

        [$first, $partial, $full] = $vehicle->refuelings()->orderBy('mileage_begin')->get()->all();

        // 20 liters refueled, and the tank was left 40% (20 liters) emptier than before.
        $this->assertEquals(8.0, $partial->fuel_consumption);
        $this->assertEquals(0.16, $partial->costs_per_kilometer);
        // 45 liters refueled, of which 20 liters made up for the time before.
        $this->assertEquals(5.0, $full->fuel_consumption);
        $this->assertEquals(0.1, $full->costs_per_kilometer);

        // Changing how full the tank was left changes the refueling after it as well.
        $this->patch(route('refuelings.update', $partial->id), $input(1500, 20, 100, 40))->assertSessionHasNoErrors();

        $this->assertEquals(4.0, $partial->refresh()->fuel_consumption);
        $this->assertEquals(9.0, $full->refresh()->fuel_consumption);

        $this->patch(route('refuelings.update', $partial->id), $input(1500, 20, 60, 40))->assertSessionHasNoErrors();

        $this->assertEquals(5.0, $full->refresh()->fuel_consumption);

        // And so does deleting it: the first refueling filled up the tank.
        $this->delete(route('refuelings.destroy', $partial->id));

        $this->assertEquals(8.0, $first->refresh()->fuel_consumption);
        $this->assertEquals(9.0, $full->refresh()->fuel_consumption);
    }

    public function test_a_refueling_must_end_at_a_higher_mileage_and_use_a_compatible_fuel()
    {
        $this->signInWithVehicle();

        $this->post(route('refuelings.store'), [
            'date' => '2026-03-01',
            'gas_station' => 'Shell',
            'fuel_type' => 'Diesel',
            'amount' => 40,
            'percentage' => 100,
            'unit_price' => 2,
            'total_price' => 80,
            'mileage_begin' => 1500,
            'mileage_end' => 1500,
        ])->assertSessionHasErrors(['fuel_type', 'mileage_end']);
    }

    public function test_storing_an_accident_calculates_the_uninsured_costs_and_marks_the_vehicle()
    {
        $vehicle = $this->signInWithVehicle();

        $this->post(route('accidents.store'), [
            'type' => 'one_sided',
            'datetime' => '2026-03-01 14:30',
            'location' => 'A2 Utrecht',
            'situation' => ['vehicle_not_rollable'],
            'damage_own' => 5000,
            'damage_own_insured' => 4000,
            'damage_others' => 1000,
            'damage_others_insured' => 1000,
            'attributes' => [['attribute_name' => 'Guard rail', 'attribute_type' => 'object']],
        ])->assertSessionHasNoErrors();

        $accident = $vehicle->accidents()->sole();

        $this->assertEquals(1000, $accident->total_price);
        $this->assertSame('Guard rail', $accident->getAttribute('attributes')[0]['attribute_name']);
        $this->assertSame('not_rollable', $vehicle->refresh()->status);
    }

    public function test_the_roads_of_a_toll_are_entered_comma_separated()
    {
        $vehicle = $this->signInWithVehicle();

        $this->post(route('toll.store'), [
            'start_date' => '2026-03-01',
            'type' => 'section',
            'country' => 'france',
            'road_type' => 'highway',
            'road' => 'A1, A26',
            'start_location' => 'Lille',
            'end_location' => 'Reims',
            'price' => 21.5,
        ])->assertSessionHasNoErrors();

        $toll = $vehicle->tolls()->sole();
        $this->assertSame(['A1', 'A26'], $toll->road);

        $this->get(route('toll.edit', $toll->id))
            ->assertInertia(fn (Assert $page) => $page->where('form.defaults.road', 'A1, A26')->etc());

        $this->post(route('toll.store'), [
            'start_date' => '2026-03-01',
            'type' => 'location',
            'road' => 'A1',
            'start_location' => 'Lille',
            'price' => 5,
        ])->assertSessionHasErrors(['country', 'road_type']);
    }

    public function test_a_check_is_ticked_off_in_the_maintenance_plan_and_left_out_of_the_list()
    {
        $vehicle = $this->signInWithVehicle(['mileage_latest' => 4200]);

        $this->post(route('maintenance-plan.done', 'fluid_levels'))->assertSessionHasNoErrors();

        $maintenance = $vehicle->maintenances()->sole();

        $this->assertSame('liquids_checked', $maintenance->type_maintenance);
        $this->assertEquals(4200, $maintenance->mileage);

        // The checks would crowd the list, nor are they a type to filter on.
        $this->get(route('maintenances.index'))
            ->assertInertia(fn (Assert $page) => $page->has('table.data', 0)->etc());
    }

    public function test_a_maintenance_can_be_viewed()
    {
        $vehicle = $this->signInWithVehicle();
        $maintenance = Maintenance::factory()->for($vehicle)->create();

        $this->get(route('maintenances.show', $maintenance->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Crud/Show')->has('sections', 2)->etc());
    }
}
