<?php

namespace Tests\Feature;

use App\Models\Refueling;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_dashboard_is_the_home_page()
    {
        $this->assertSame(url('/'), route('dashboard'));
    }

    public function test_users_without_a_vehicle_are_sent_to_create_their_first_one()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect(route('vehicles.create'));
        $this->get(route('refuelings.index'))->assertRedirect(route('vehicles.create'));
    }

    public function test_users_with_a_vehicle_can_visit_the_dashboard()
    {
        $vehicle = $this->signInWithVehicle();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('currentVehicle.id', $vehicle->id)
                ->where('usesFuel', true)
                ->missing('stats'));
    }

    public function test_the_dashboard_widgets_are_loaded_as_deferred_props()
    {
        $vehicle = $this->signInWithVehicle();

        Refueling::factory()->for($vehicle)->count(3)->create([
            'date' => now()->toDateString(),
            'gas_station' => 'Shell Utrecht',
            'fuel_type' => 'Unleaded 95 (E10)',
            'charge_time' => null,
            'total_price' => 60,
        ]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->has('notifications')
                    ->has('statuses')
                    ->has('stats.0.title')
                    ->has('fuelPricesAbroad.countries')
                    ->has('fuelUsageByType')
                    ->where('cheapestGasStations.0.gasStation', 'Shell Utrecht')
                    ->where('cheapestGasStations.0.visits', 3)
                    ->has('averageMonthlyCosts', 1)
                    ->where('averageMonthlyCosts.0.label', 'Fuel')
                    ->where('costsChart.series.0.key', 0)
                    ->has('latestCosts', 3)
                    ->etc()));
    }

    public function test_the_notifications_are_ordered_by_urgency()
    {
        $this->signInWithVehicle();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('status', fn (Assert $reload) => $reload
                    ->where('notifications', function ($notifications) {
                        $priorities = collect($notifications)->pluck('priority');

                        return $priorities->isNotEmpty()
                            && $priorities->all() === $priorities->sort()->values()->all();
                    })
                    ->etc()));
    }

    public function test_the_costs_chart_has_a_series_for_every_cost_type_with_costs()
    {
        $vehicle = $this->signInWithVehicle();

        $vehicle->products()->create(['name' => 'Oil', 'price' => 20, 'date' => now()->toDateString()]);
        $vehicle->services()->create(['type' => 'coffee', 'name' => 'Coffee', 'price' => 5, 'date' => now()->toDateString()]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('costs', fn (Assert $reload) => $reload
                    ->has('costsChart.series', 2)
                    ->where('costsChart.series.0.label', 'Product')
                    ->where('costsChart.series.1.label', 'Service')
                    ->has('averageMonthlyCosts', 2)
                    ->where('averageMonthlyCosts.0.label', 'Product')
                    ->where('averageMonthlyCosts.0.share', 100)
                    ->etc()));
    }

    public function test_the_costs_can_be_limited_to_a_period_and_cost_types()
    {
        $vehicle = $this->signInWithVehicle();

        $vehicle->products()->create(['name' => 'Oil', 'price' => 20, 'date' => '2026-03-10']);
        $vehicle->products()->create(['name' => 'Wipers', 'price' => 15, 'date' => '2025-03-10']);
        $vehicle->services()->create(['type' => 'coffee', 'name' => 'Coffee', 'price' => 5, 'date' => '2026-03-12']);

        $this->get(route('dashboard', ['dashboard_start' => '2026-03-01', 'dashboard_end' => '2026-03-31', 'cost_types' => ['Product']]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.costTypes', ['Product'])
                ->has('costTypeOptions.Product')
                ->loadDeferredProps('costs', fn (Assert $reload) => $reload
                    ->has('latestCosts', 1)
                    ->where('latestCosts.0.item', 'Oil')
                    ->has('averageMonthlyCosts', 1)
                    ->etc()));

        $this->get(route('dashboard', ['cost_types' => ['Nonsense']]))
            ->assertSessionHasErrors('cost_types.0');
    }

    public function test_the_date_filter_must_be_a_valid_range()
    {
        $this->signInWithVehicle();

        $this->get(route('dashboard', ['dashboard_start' => '2026-02-01', 'dashboard_end' => '2026-01-01']))
            ->assertSessionHasErrors('dashboard_end');
    }
}
