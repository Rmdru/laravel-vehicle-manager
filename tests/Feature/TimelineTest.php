<?php

namespace Tests\Feature;

use App\Models\Refueling;
use App\Models\Toll;
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
            'date' => '2026-04-02',
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
        $vehicle = $this->signInWithVehicle(['maintenance_interval_time' => 12]);

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

        $this->get(route('timeline'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('predictions', fn ($predictions) => collect($predictions)
                    ->flatMap(fn ($month) => $month['items'])
                    ->pluck('heading')
                    ->sort()
                    ->values()
                    ->all() === ['Maintenance', 'Road tax'])
                ->etc());
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
                    ->pluck('heading')
                    ->all() === ['MOT', 'Vignette'])
                ->etc());
    }

    public function test_the_next_maintenance_follows_the_intervals_of_the_vehicle()
    {
        $vehicle = $this->signInWithVehicle(['maintenance_interval_time' => 12, 'maintenance_interval_distance' => 15000, 'mileage_latest' => 6000]);

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
        $vehicle = $this->signInWithVehicle(['maintenance_interval_time' => 12, 'maintenance_interval_distance' => 15000, 'mileage_latest' => 6000]);

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
            ->pluck('date')
            ->all();
    }

    public function test_an_empty_timeline_can_be_shown()
    {
        $this->signInWithVehicle();

        $this->get(route('timeline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('history', 0)->has('predictions', 0)->etc());
    }
}
