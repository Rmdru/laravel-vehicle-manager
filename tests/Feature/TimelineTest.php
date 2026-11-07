<?php

namespace Tests\Feature;

use App\Models\Finding;
use App\Models\Refueling;
use App\Models\Toll;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\TimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_timeline_groups_the_history_by_month_with_the_newest_first()
    {
        $vehicle = $this->signInWithVehicle();

        Refueling::factory()->for($vehicle)->create(['date' => '2026-03-10', 'gas_station' => 'Shell Utrecht', 'charge_time' => null]);
        Toll::factory()->for($vehicle)->create([
            'start_date' => '2026-04-02',
            'country' => 'netherlands',
            'road_type' => 'highway',
            'road' => ['A2'],
            'start_location' => 'Utrecht',
            'end_location' => null,
        ]);

        $this->get(route('timeline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Timeline')
                ->has('history', 2)
                ->where('history.0.month', 'April 2026')
                ->where('history.0.items.0.heading', 'Toll')
                ->where('history.0.items.0.roads.0.label', 'A2')
                ->where('history.0.items.0.flag.isoCode', 'NL')
                ->where('history.1.items.0.location', 'Shell Utrecht')
                ->where('history.1.items.0.url', route('refuelings.edit', $vehicle->refuelings()->sole()->id))
                ->etc());
    }

    public function test_the_timeline_predicts_the_next_maintenance_and_invoices()
    {
        // After the invoice day: this month's invoices are done.
        $this->travelTo('2026-10-20 12:00:00');
        $vehicle = $this->signInWithVehicle(['maintenance_intervals' => [['types' => ['maintenance'], 'km' => null, 'months' => 12]]]);

        $vehicle->maintenances()->create([
            'type_maintenance' => 'maintenance',
            'date' => now()->subMonths(2)->toDateString(),
            'mileage' => 1000,
            'total_price' => 300,
        ]);
        $vehicle->taxes()->create([
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'price' => 40,
            'invoice_day' => 15,
        ]);

        $vehicle->insurances()->create([
            'insurance_company' => 'FBTO',
            'type' => 'allrisk',
            'start_date' => now()->subMonths(6)->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'price' => 100,
            'invoice_day' => 15,
        ]);

        $predictions = collect(app(TimelineService::class)->predictions($vehicle->fresh()));
        $items = $predictions->flatMap(fn ($month) => $month['items']);

        $this->assertContains('Maintenance', $items->pluck('heading'));
        // Insurance and road tax: one row a month, within the year ahead.
        $fixed = $items->where('heading', 'Fixed costs')->sortBy('sort')->values();
        $this->assertCount(12, $fixed);
        $this->assertSame('€ 140,00', $fixed->first()['price']);
        // After the insurance ends: the road tax only.
        $this->assertSame('€ 40,00', $fixed->last()['price']);
        $this->assertContains('Insurance ends', $items->pluck('heading'));
        // Per month what to expect: the fixed costs, and the maintenance of the past year on average.
        $this->assertNotNull($predictions->first(fn ($month) => collect($month['items'])->contains('key', $fixed->first()['key']))['expected']);
    }

    public function test_the_timeline_predicts_the_components_of_the_plan_trips_and_the_garage_list()
    {
        $this->travelTo('2026-10-23 12:00:00');
        $vehicle = $this->signInWithVehicle([
            'construction_date' => '2026-01-01',
            'mileage_start' => 10,
            'maintenance_intervals' => [['types' => ['maintenance'], 'km' => null, 'months' => 12]],
        ]);
        $vehicle->maintenances()->create(['type_maintenance' => 'maintenance', 'date' => '2026-06-01', 'mileage' => 5000, 'total_price' => 300]);
        Finding::factory()->for($vehicle)->create(['title' => 'Rattle front left', 'for_garage' => true]);
        $trip = Trip::create([
            'user_id' => $vehicle->user_id,
            'vehicle_id' => $vehicle->id,
            'name' => 'Pairi Daiza',
            'time_at' => '2026-12-25 08:00:00',
            'status' => Trip::STATUS_PLANNED,
            'plan' => ['routes' => [['distance' => 218000, 'costs' => ['total' => 45.5]]]],
        ]);

        $items = collect(app(TimelineService::class)->predictions($vehicle->fresh()))->flatMap(fn ($month) => $month['items']);

        // The garage list goes along with the next maintenance.
        $this->assertSame($items->firstWhere('heading', 'Maintenance')['date'], $items->firstWhere('heading', 'Rattle front left')['date']);
        // The trip, with what it is expected to cost.
        $planned = $items->firstWhere('heading', 'Pairi Daiza');
        $this->assertSame('€ 45,50', $planned['price']);
        $this->assertSame(route('trips.show', $trip), $planned['url']);
        // Of the plan: the wiper blades a year after it was built; the brake fluid after two years, beyond the year ahead.
        $plan = $items->filter(fn ($item) => str_starts_with($item['key'], 'plan-'));
        $wipers = $plan->first(fn ($item) => str_contains($item['heading'], 'Wiper blades'));
        $this->assertSame('2027-01-01', date('Y-m-d', $wipers['sort']));
        $this->assertSame(route('maintenance-plan'), $wipers['url']);
        $this->assertFalse($plan->contains(fn ($item) => str_contains($item['heading'], 'Brake fluid')));
    }

    public function test_the_timeline_predicts_the_mot_and_expiring_vignettes()
    {
        $vehicle = $this->signInWithVehicle(['rdw_data' => ['vervaldatum_apk_dt' => now()->addMonths(3)->toDateString()]]);

        $vehicle->vignettes()->create(['start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonths(2)->toDateString(), 'price' => 20, 'country' => 'austria']);
        $vehicle->vignettes()->create(['start_date' => now()->subYear()->toDateString(), 'end_date' => now()->subMonth()->toDateString(), 'price' => 20, 'country' => 'austria']);
        $vehicle->environmentalStickers()->create(['start_date' => now()->subMonth()->toDateString(), 'end_date' => null, 'price' => 5, 'country' => 'germany']);

        $this->get(route('timeline'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('predictions', fn ($predictions) => collect($predictions)
                    ->flatMap(fn ($month) => $month['items'])
                    // Not the components of the plan of the random vehicle.
                    ->reject(fn ($item) => str_starts_with($item['key'], 'plan-'))
                    ->pluck('heading')
                    ->values()
                    ->all() === ['MOT', 'Vignette'])
                ->etc());
    }

    public function test_the_next_maintenance_follows_the_intervals_of_the_vehicle()
    {
        $vehicle = $this->signInWithVehicle(['maintenance_intervals' => [['types' => Vehicle::MAINTENANCE_TYPES, 'km' => 15000, 'months' => 12]], 'mileage_latest' => 6000]);

        // A small maintenance no longer has an interval of its own of 6 months.
        $vehicle->maintenances()->create([
            'type_maintenance' => 'small_maintenance',
            'date' => now()->subMonths(8)->toDateString(),
            'mileage' => 1000,
            'total_price' => 100,
        ]);

        $this->assertSame([now()->subMonths(8)->addMonths(12)->isoFormat('ll')], $this->predictedDates($vehicle));
    }

    public function test_the_next_maintenance_comes_sooner_when_the_distance_is_driven_first()
    {
        $vehicle = $this->signInWithVehicle(['maintenance_intervals' => [['types' => Vehicle::MAINTENANCE_TYPES, 'km' => 15000, 'months' => 12]], 'mileage_latest' => 6000]);

        $vehicle->maintenances()->create([
            'type_maintenance' => 'maintenance',
            'date' => now()->subMonths(2)->toDateString(),
            'mileage' => 1000,
            'total_price' => 300,
        ]);

        // Driving 3000 km a month the 10000 km that are left take less than the 10 months.
        Refueling::factory()->for($vehicle)->create(['date' => now()->subDays(40)->toDateString(), 'mileage_begin' => 3000, 'mileage_end' => 6000, 'charge_time' => null]);

        $this->assertSame([now()->addDays(10000 / (3000 / 30.44))->isoFormat('ll')], $this->predictedDates($vehicle));
    }

    /**
     * @return list<string>
     */
    private function predictedDates(Vehicle $vehicle): array
    {
        return collect(app(TimelineService::class)->predictions($vehicle->fresh()))
            ->flatMap(fn ($month) => $month['items'])
            // The maintenance, not the components of the plan of the random vehicle.
            ->reject(fn ($item) => str_starts_with($item['key'], 'plan-'))
            ->pluck('date')
            ->values()
            ->all();
    }

    public function test_an_empty_timeline_can_be_shown()
    {
        $this->signInWithVehicle();

        // Without records: of the predictions only what the plan tells from the construction date.
        $this->get(route('timeline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 0)
                ->where('predictions', fn ($predictions) => collect($predictions)
                    ->flatMap(fn ($month) => $month['items'])
                    ->every(fn ($item) => str_starts_with($item['key'], 'plan-')))
                ->etc());
    }
}
