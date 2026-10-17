<?php

namespace Tests\Feature;

use App\Models\FuelPrice;
use App\Models\Reconditioning;
use App\Models\Refueling;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
                    ->where('topGasStations.fuelTypes.0.cheapest.0.gasStation', 'Shell Utrecht')
                    ->where('topGasStations.fuelTypes.0.cheapest.0.visits', 3)
                    ->has('averageMonthlyCosts', 1)
                    ->where('averageMonthlyCosts.0.label', 'Fuel')
                    ->where('costsChart.series.0.key', 0)
                    ->has('latestCosts', 3)
                    ->etc()));
    }

    public function test_the_top_gas_stations_are_ranked_per_fuel_type()
    {
        $vehicle = $this->signInWithVehicle();

        $refuel = fn (string $station, string $fuelType, float $price) => Refueling::factory()->for($vehicle)->create([
            'date' => now()->toDateString(),
            'gas_station' => $station,
            'fuel_type' => $fuelType,
            'unit_price' => $price,
        ]);

        $refuel('Shell', 'Unleaded 95 (E10)', 1.9);
        $refuel('Esso', 'Unleaded 95 (E10)', 2.0);
        $refuel('Esso', 'Unleaded 95 (E10)', 2.0);
        $refuel('Tango', 'Super Plus 98', 2.1);
        $refuel('Shell', 'Super Plus 98', 2.4);

        $top = app(DashboardService::class)->topGasStations($vehicle, null, null);
        $groups = $top['fuelTypes'];

        $this->assertSame(['Unleaded 95 (E10)', 'Super Plus 98'], array_column($groups, 'key'));
        $this->assertSame(['Shell', 'Esso'], array_column($groups[0]['cheapest'], 'gasStation'));
        $this->assertSame(['Esso', 'Shell'], array_column($groups[0]['mostExpensive'], 'gasStation'));
        $this->assertSame(['Tango', 'Shell'], array_column($groups[1]['cheapest'], 'gasStation'));

        // Of all fuel types together, a station per fuel type.
        $this->assertSame(['Shell', 'Esso', 'Tango', 'Shell'], array_column($top['all']['cheapest'], 'gasStation'));
        $this->assertSame(['Unleaded 95 (E10)', 'Unleaded 95 (E10)', 'Super Plus 98', 'Super Plus 98'], array_column($top['all']['cheapest'], 'fuelType'));
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

    public function test_old_e10_fuel_is_reported_and_other_fuel_is_not()
    {
        $vehicle = $this->signInWithVehicle();

        $refueling = Refueling::factory()->for($vehicle)->create([
            'date' => now()->subMonths(2)->toDateString(),
            'fuel_type' => 'Unleaded 95 (E10)',
            'charge_time' => null,
        ]);

        $keys = fn () => collect(app(DashboardService::class)->notifications($vehicle->fresh()))->pluck('key');

        $this->assertContains('old_fuel', $keys());

        $refueling->update(['fuel_type' => 'Super Plus (E5)']);

        $this->assertNotContains('old_fuel', $keys());
    }

    public function test_the_newest_refueling_is_the_one_refueled_last_not_the_one_entered_last()
    {
        $vehicle = $this->signInWithVehicle();

        $recent = Refueling::factory()->for($vehicle)->create(['date' => now()->subDay()->toDateString(), 'charge_time' => null, 'created_at' => now()->subHour()]);
        Refueling::factory()->for($vehicle)->create(['date' => now()->subYear()->toDateString(), 'charge_time' => null, 'created_at' => now()]);

        $this->assertTrue($vehicle->refuelings()->newest()->first()->is($recent));
    }

    public function test_the_dashboard_can_be_shown_with_an_insurance_and_tax_that_have_yet_to_start()
    {
        $vehicle = $this->signInWithVehicle();

        $period = ['start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addYear()->toDateString(), 'price' => 40, 'invoice_day' => 1];
        $vehicle->insurances()->create([...$period, 'insurance_company' => 'Insurer', 'type' => 0]);
        $vehicle->taxes()->create($period);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('status', fn (Assert $reload) => $reload->has('notifications')->has('statuses')->etc()));
    }

    public function test_the_weather_for_the_washing_advice_is_that_of_the_city_of_the_user()
    {
        $vehicle = $this->signInWithVehicle();
        $vehicle->user->update(['city' => 'Utrecht', 'latitude' => 52.09083, 'longitude' => 5.12222]);
        Reconditioning::factory()->for($vehicle)->create(['type' => 'carwash', 'date' => now()->subDays(25)->toDateString()]);

        app(DashboardService::class)->notifications($vehicle->fresh());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'open-meteo.com') && (float) $request['latitude'] === 52.09083);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ip-api.com'));
    }

    public function test_without_a_city_the_weather_is_that_of_the_ip_address()
    {
        $vehicle = $this->signInWithVehicle();
        Reconditioning::factory()->for($vehicle)->create(['type' => 'carwash', 'date' => now()->subDays(25)->toDateString()]);

        app(DashboardService::class)->notifications($vehicle->fresh());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ip-api.com'));
    }

    public function test_refreshing_the_fuel_prices_happens_in_the_background_and_not_twice_in_a_row()
    {
        $this->signInWithVehicle();
        $this->withoutDefer();

        $this->post(route('fuel-prices.refresh'))->assertRedirect();
        $this->post(route('fuel-prices.refresh'))->assertRedirect();

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'opendata.cbs.nl')));
    }

    public function test_the_fuel_prices_abroad_are_compared_with_the_country_of_registration()
    {
        $vehicle = $this->signInWithVehicle(['country_registration' => 'germany']);

        foreach (['netherlands', 'germany'] as $country) {
            FuelPrice::create(['date' => now()->toDateString(), 'country' => $country, 'fuel_type' => 'Diesel', 'price' => 1.5]);
        }

        $home = fn () => collect(app(DashboardService::class)->fuelPricesAbroad($vehicle->fresh())['countries'])->firstWhere('isHome', true)['key'] ?? null;

        $this->assertSame('germany', $home());

        // Without prices of the country of registration the Netherlands is home.
        $vehicle->update(['country_registration' => 'france']);

        $this->assertSame('netherlands', $home());
    }

    public function test_the_fuel_prices_chart_has_the_countries_with_prices_in_the_period()
    {
        $this->signInWithVehicle();

        FuelPrice::create(['date' => '2026-03-01', 'country' => 'netherlands', 'fuel_type' => 'Diesel', 'price' => 1.5]);
        FuelPrice::create(['date' => '2026-03-08', 'country' => 'netherlands', 'fuel_type' => 'Diesel', 'price' => 1.6]);
        FuelPrice::create(['date' => '2026-03-08', 'country' => 'netherlands', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => 2.1]);
        FuelPrice::create(['date' => '2025-03-01', 'country' => 'germany', 'fuel_type' => 'Diesel', 'price' => 1.4]);

        $this->get(route('dashboard', ['dashboard_start' => '2026-03-01', 'dashboard_end' => '2026-03-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('fuel', fn (Assert $reload) => $reload
                    // Germany has no prices in March 2026.
                    ->where('fuelPricesChart.home', 'netherlands')
                    ->has('fuelPricesChart.countries', 1)
                    ->where('fuelPricesChart.countries.0.key', 'netherlands')
                    ->has('fuelPricesChart.countries.0.series', 2)
                    // A fuel type has the same key, and so the same color, everywhere.
                    ->where('fuelPricesChart.countries.0.series.0.key', 0)
                    ->where('fuelPricesChart.countries.0.series.1.label', 'Diesel')
                    ->where('fuelPricesChart.countries.0.series.1.key', 1)
                    ->where('fuelPricesChart.countries.0.series.1.points', [
                        ['date' => '2026-03-01', 'price' => 1.5],
                        ['date' => '2026-03-08', 'price' => 1.6],
                    ])
                    ->etc()));

        $this->get(route('dashboard', ['dashboard_start' => '2024-01-01', 'dashboard_end' => '2024-01-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('fuel', fn (Assert $reload) => $reload->has('fuelPricesChart.countries', 0)->etc()));
    }

    public function test_the_prices_abroad_are_the_most_recent_price_of_every_fuel_type()
    {
        $vehicle = $this->signInWithVehicle();
        $monday = now()->subDays(3)->toDateString();

        // The CBS has the prices of Monday, the ANWB has those of every day since.
        FuelPrice::create(['date' => $monday, 'country' => 'netherlands', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => 2.43]);
        FuelPrice::create(['date' => $monday, 'country' => 'netherlands', 'fuel_type' => 'Super Plus (E5)', 'price' => 2.70]);
        FuelPrice::create(['date' => now()->toDateString(), 'country' => 'netherlands', 'fuel_type' => 'Super Plus (E5)', 'price' => 2.73]);

        $prices = collect(app(DashboardService::class)->fuelPricesAbroad($vehicle->fresh())['countries'])->firstWhere('key', 'netherlands')['prices'];

        $this->assertSame(['2,430', now()->subDays(3)->format('d-m-Y')], [$prices['Unleaded 95 (E10)']['price'], $prices['Unleaded 95 (E10)']['date']]);
        $this->assertSame(['2,730', now()->format('d-m-Y')], [$prices['Super Plus (E5)']['price'], $prices['Super Plus (E5)']['date']]);
    }
}
