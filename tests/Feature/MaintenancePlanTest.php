<?php

namespace Tests\Feature;

use App\Ai\Agents\MaintenancePlanAdvisor;
use App\Ai\Agents\MaintenanceTaskClassifier;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Services\DashboardService;
use App\Services\MaintenancePlanAi;
use App\Services\MaintenancePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MaintenancePlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 12:00:00');
        // A single key, whatever .env holds.
        config(['ai.providers.gemini.key' => 'test-key']);
    }

    /**
     * A vehicle driven 1.000 km a month for a year, with a maintenance that
     * replaced the oil and its filter, and one that only had the MOT.
     */
    private function vehicle(array $attributes = []): Vehicle
    {
        $vehicle = $this->signInWithVehicle([
            'purchase_date' => '2025-10-23',
            'construction_date' => '2016-05-01',
            'mileage_start' => 10000,
            ...$attributes,
        ]);

        Maintenance::factory()->for($vehicle)->create([
            'date' => '2026-01-23',
            'mileage' => 13000,
            'airco_check' => false,
            'tasks' => [
                ['task' => 'Olie en oliefilter vervangen', 'price' => 120, 'components' => ['engine_oil', 'oil_filter']],
                ['task' => 'Ruitenwissers', 'price' => 30],
            ],
        ]);

        Maintenance::factory()->for($vehicle)->create([
            'date' => '2026-10-23',
            'mileage' => 22000,
            'airco_check' => false,
            'tasks' => [['task' => 'APK keuring', 'price' => 50]],
        ]);

        return $vehicle->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Vehicle $vehicle, string $key): array
    {
        return collect(app(MaintenancePlanService::class)->plan($vehicle))->firstWhere('key', $key);
    }

    public function test_a_component_is_due_after_its_interval_by_time_or_distance()
    {
        config(['maintenance_plan.components.engine_oil.km' => 10000, 'maintenance_plan.components.engine_oil.months' => 12]);
        $vehicle = $this->vehicle();

        $oil = $this->item($vehicle, 'engine_oil');

        $this->assertSame('2026-01-23', $oil['done']['date']);
        $this->assertSame(13000, $oil['done']['km']);
        $this->assertEquals(120, $oil['done']['price']);
        // 23.000 km comes before January 2027, at 1.000 km a month.
        $this->assertSame(23000, $oil['due_km']);
        $this->assertSame('distance', $oil['due_by']);
        $this->assertSame(1000, $oil['remaining_km']);
        $this->assertSame('2026-11-23', $oil['due_date']);
        $this->assertSame(MaintenancePlanService::SOON, $oil['state']);

        // Never recorded: counted from new, and from 2016 it is overdue, so to be checked.
        $fluid = $this->item($vehicle, 'brake_fluid');
        $this->assertSame(MaintenancePlanService::CHECK, $fluid['state']);
        $this->assertSame('2016-05-01', $fluid['assumed_since']);
        $this->assertNull($fluid['done']);
    }

    public function test_on_a_young_vehicle_what_was_never_recorded_is_counted_from_new()
    {
        $vehicle = $this->vehicle(['construction_date' => '2026-01-01', 'purchase_date' => '2026-01-01', 'mileage_start' => 10]);

        $fluid = $this->item($vehicle, 'brake_fluid');

        // The original one: due two years after it was built, and not to be checked.
        $this->assertSame('2028-01-01', $fluid['due_date']);
        $this->assertSame(MaintenancePlanService::OK, $fluid['state']);
        $this->assertSame('2026-01-01', $fluid['assumed_since']);
    }

    public function test_what_is_to_be_checked_needs_attention_without_a_notification_for_each()
    {
        $notifications = collect(app(MaintenancePlanService::class)->notifications($this->vehicle()))->keyBy('key');

        // Expensive or dangerous to miss: red, each of its own, with the date to fill in.
        $this->assertSame('critical', $notifications['maintenance_plan:brake_fluid']['type']);
        $this->assertSame('critical', $notifications['maintenance_plan:timing_belt']['type']);
        $this->assertSame(route('maintenance-plan', ['edit' => 'brake_fluid']), $notifications['maintenance_plan:brake_fluid']['url']);
        // A check of its own, orange, to tick off.
        $this->assertSame('warning', $notifications['maintenance_plan:tire_pressure']['type']);
        $this->assertNotNull($notifications['maintenance_plan:tire_pressure']['doneUrl']);
        // The rest together.
        $this->assertSame('warning', $notifications['maintenance_plan:check']['type']);
        $this->assertArrayNotHasKey('maintenance_plan:cabin_filter', $notifications->all());
    }

    public function test_what_the_owner_filled_in_counts_when_it_is_more_recent()
    {
        $vehicle = $this->vehicle();
        VehicleComponent::create(['vehicle_id' => $vehicle->id, 'key' => 'brake_fluid', 'interval_months' => 24, 'done_date' => '2024-09-01']);

        $fluid = $this->item($vehicle->fresh(), 'brake_fluid');

        $this->assertSame('2026-09-01', $fluid['due_date']);
        $this->assertSame(MaintenancePlanService::OVERDUE, $fluid['state']);
    }

    public function test_an_electric_vehicle_has_a_plan_too_without_the_engine()
    {
        $vehicle = $this->vehicle(['powertrain' => 'electricity', 'fuel_types' => []]);

        $keys = array_column(app(MaintenancePlanService::class)->plan($vehicle), 'key');

        $this->assertContains('cabin_filter', $keys);
        $this->assertContains('brake_fluid', $keys);
        $this->assertNotContains('engine_oil', $keys);
        $this->assertNotContains('spark_plugs', $keys);
    }

    public function test_replacing_the_front_tires_leaves_the_rear_ones_alone()
    {
        $vehicle = $this->vehicle();
        Maintenance::factory()->for($vehicle)->create([
            'date' => '2026-05-01',
            'mileage' => 17000,
            'airco_check' => false,
            'tasks' => [['task' => '2 nieuwe banden voor', 'price' => 240, 'components' => ['front_tires']]],
        ]);

        $vehicle = $vehicle->fresh();

        $this->assertSame('2026-05-01', $this->item($vehicle, 'front_tires')['done']['date']);
        $this->assertNull($this->item($vehicle, 'rear_tires')['done']);
    }

    public function test_every_component_has_an_icon_that_is_there()
    {
        foreach (config('maintenance_plan.components') as $key => $component) {
            [$set, $name] = explode(':', $component['icon']);

            $this->assertFileExists(resource_path("icons/{$set}/{$name}.svg"), "The icon of [{$key}] is not in resources/icons.");
        }
    }

    public function test_the_intake_cleaning_is_only_in_the_plan_when_switched_on()
    {
        $vehicle = $this->vehicle();

        $this->assertNotContains('intake_cleaning', array_column(app(MaintenancePlanService::class)->plan($vehicle), 'key'));

        $this->patch(route('maintenance-plan.update', 'intake_cleaning'), ['active' => true])->assertRedirect();

        $this->assertContains('intake_cleaning', array_column(app(MaintenancePlanService::class)->plan($vehicle->fresh()), 'key'));
    }

    public function test_the_overdue_components_need_attention()
    {
        $vehicle = $this->vehicle();
        VehicleComponent::create(['vehicle_id' => $vehicle->id, 'key' => 'brake_fluid', 'interval_months' => 24, 'done_date' => '2024-09-01']);

        $notifications = collect(app(DashboardService::class)->notifications($vehicle->fresh()));

        $this->assertSame('critical', $notifications->firstWhere('key', 'maintenance_plan:brake_fluid')['type']);
    }

    public function test_a_component_due_soon_is_shown_in_orange()
    {
        config(['maintenance_plan.components.engine_oil.km' => 10000, 'maintenance_plan.components.engine_oil.months' => 12]);
        $vehicle = $this->vehicle();

        $oil = collect(app(MaintenancePlanService::class)->notifications($vehicle))->firstWhere('key', 'maintenance_plan:engine_oil');

        // Due within 1.000 km: soon rather than due.
        $this->assertSame('warning', $oil['type']);
    }

    public function test_the_owner_can_change_a_component_and_the_model_leaves_it_alone_then()
    {
        $vehicle = $this->vehicle();

        $this->patch(route('maintenance-plan.update', 'timing_belt'), ['active' => false])->assertRedirect();
        $this->patch(route('maintenance-plan.update', 'spark_plugs'), ['active' => true, 'interval_km' => 90000, 'interval_months' => null])->assertRedirect();

        $this->assertFalse(VehicleComponent::where('key', 'timing_belt')->sole()->active);
        $this->assertSame(VehicleComponent::MANUAL, VehicleComponent::where('key', 'spark_plugs')->sole()->source);

        MaintenancePlanAdvisor::fake([['components' => [
            ['key' => 'spark_plugs', 'applies' => true, 'interval_km' => 60000, 'interval_months' => 48, 'note' => ''],
            ['key' => 'cabin_filter', 'applies' => true, 'interval_km' => 20000, 'interval_months' => 24, 'note' => 'Volgens schema'],
            ['key' => 'flux_capacitor', 'applies' => true, 'interval_km' => 1, 'interval_months' => 1, 'note' => ''],
        ]]]);

        $this->post(route('maintenance-plan.propose'))->assertRedirect();

        $this->assertSame(90000, VehicleComponent::where('key', 'spark_plugs')->sole()->interval_km);
        $cabin = VehicleComponent::where('key', 'cabin_filter')->sole();
        $this->assertSame([20000, 24, 'ai', 'Volgens schema'], [$cabin->interval_km, $cabin->interval_months, $cabin->source, $cabin->note]);
        $this->assertSame(2, VehicleComponent::where('vehicle_id', $vehicle->id)->count() - 1);
    }

    public function test_the_interval_of_a_single_component_can_be_asked_for_even_when_set_by_hand()
    {
        $vehicle = $this->vehicle();
        $this->patch(route('maintenance-plan.update', 'spark_plugs'), ['active' => true, 'interval_km' => 90000, 'interval_months' => null]);

        MaintenancePlanAdvisor::fake([['components' => [
            ['key' => 'spark_plugs', 'applies' => true, 'interval_km' => 60000, 'interval_months' => 48, 'note' => 'Volgens schema'],
        ]]]);

        $this->post(route('maintenance-plan.propose-one', 'spark_plugs'))->assertRedirect();

        $plugs = VehicleComponent::where('key', 'spark_plugs')->sole();
        $this->assertSame([60000, 48, VehicleComponent::AI], [$plugs->interval_km, $plugs->interval_months, $plugs->source]);
        // Only this component was asked for.
        MaintenancePlanAdvisor::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'spark_plugs') && ! str_contains($prompt->prompt, 'cabin_filter'));
        $this->assertSame(1, VehicleComponent::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_the_tasks_are_sorted_out_but_only_stored_when_the_owner_saves()
    {
        $vehicle = $this->vehicle();
        MaintenanceTaskClassifier::fake([['tasks' => [
            ['number' => 1, 'components' => ['wiper_blades']],
            ['number' => 2, 'components' => []],
        ]]]);

        $this->assertSame(['Ruitenwissers', 'APK keuring'], app(MaintenancePlanAi::class)->unsortedTasks($vehicle));

        $this->post(route('maintenance-plan.classify'))->assertRedirect();

        $this->assertSame(['Ruitenwissers' => ['wiper_blades']], app(MaintenancePlanAi::class)->proposals($vehicle));
        $this->assertNull(Maintenance::where('date', '2026-01-23')->sole()->tasks[1]['components'] ?? null);

        $this->post(route('maintenance-plan.assign'), ['assignments' => [['task' => 'Ruitenwissers', 'components' => ['wiper_blades']]]])->assertRedirect();

        $this->assertSame(['wiper_blades'], Maintenance::where('date', '2026-01-23')->sole()->tasks[1]['components']);
        $this->assertSame([], app(MaintenancePlanAi::class)->proposals($vehicle));
        $this->assertSame('2026-01-23', $this->item($vehicle->fresh(), 'wiper_blades')['done']['date']);
    }

    public function test_the_components_of_a_task_are_stored_with_the_maintenance()
    {
        $this->signInWithVehicle();

        $this->post(route('maintenances.store'), [
            'date' => '2026-10-20',
            'mileage' => 5000,
            'total_price' => 80,
            'type_maintenance' => 'oil_service',
            'tasks' => [['task' => 'Olie verversen', 'price' => 80, 'components' => ['engine_oil']]],
        ])->assertRedirect();

        $maintenance = Maintenance::sole();
        $this->assertSame('oil_service', $maintenance->type_maintenance);
        $this->assertSame(['engine_oil'], $maintenance->tasks[0]['components']);
    }

    public function test_the_plan_page_shows_the_components()
    {
        $this->vehicle();

        $this->get(route('maintenance-plan'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MaintenancePlan')
                ->where('proposed', false)
                ->where('unsortedTasks', 2)
                ->has('components.0.stateLabel'));
    }
}
