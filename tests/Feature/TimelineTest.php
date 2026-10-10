<?php

namespace Tests\Feature;

use App\Models\Refueling;
use App\Models\Toll;
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

    public function test_an_empty_timeline_can_be_shown()
    {
        $this->signInWithVehicle();

        $this->get(route('timeline'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('history', 0)->has('predictions', 0)->etc());
    }
}
