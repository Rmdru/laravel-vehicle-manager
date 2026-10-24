<?php

namespace Tests\Feature;

use App\Models\FuelDetourAggregate;
use App\Models\FuelPrice;
use App\Models\Maintenance;
use App\Models\Reconditioning;
use App\Models\Refueling;
use App\Models\User;
use App\Models\Vehicle;
use App\Pipelines\ImportFuelPrices\CalculateFuelDetourAggregates;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_the_next_refueling_is_shown_with_the_other_statuses()
    {
        $this->travelTo('2026-10-24 12:00:00');
        $vehicle = $this->signInWithVehicle(['powertrain' => 'gasoline', 'tank_capacity' => 50]);
        $statuses = fn () => collect(app(DashboardService::class)->statuses($vehicle->fresh()))->keyBy('title');

        $this->assertArrayNotHasKey('Next refueling', $statuses());

        // 50 km a day on 6 l/100 km: a full tank of 50 l lasts 14 days, down to the reserve.
        foreach (['2026-10-01', '2026-10-11', '2026-10-21'] as $i => $date) {
            Refueling::factory()->for($vehicle)->create([
                'date' => $date,
                'mileage_begin' => 10000 + $i * 500,
                'mileage_end' => 10500 + $i * 500,
                'fuel_consumption' => 6,
                'percentage' => 100,
            ]);
        }

        $this->assertSame([
            'title' => 'Next refueling',
            'primary' => '11 days from now',
            'secondary' => 'We 4 Nov · ± 82% in the tank',
            'icon' => 'ic:round-local-gas-station',
        ], $statuses()['Next refueling']);

        $this->travelTo('2026-11-06 12:00:00');

        $this->assertSame('Now', $statuses()['Next refueling']['primary']);
    }

    public function test_a_maintenance_past_its_distance_is_due_now_not_in_negative_kilometers()
    {
        $this->travelTo('2026-10-24 12:00:00');
        $vehicle = $this->signInWithVehicle([
            'mileage_start' => 60000,
            'mileage_latest' => 95000,
            'maintenance_intervals' => [['types' => Vehicle::MAINTENANCE_TYPES, 'km' => 15000, 'months' => 12]],
        ]);
        Maintenance::factory()->for($vehicle)->create(['date' => '2026-05-24', 'type_maintenance' => 'maintenance', 'mileage' => 78000]);

        $status = collect(app(DashboardService::class)->statuses($vehicle->fresh()))->firstWhere('title', 'Maintenance');

        $this->assertSame('Now', $status['primary']);
        $this->assertSame('6 months from now', $status['secondary']);
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
        $vehicle = $this->signInWithVehicle(['tank_capacity' => 50]);

        $refueling = Refueling::factory()->for($vehicle)->create([
            'date' => now()->subMonths(2)->toDateString(),
            'fuel_type' => 'Unleaded 95 (E10)',
            'amount' => 50,
            'charge_time' => null,
        ]);

        $keys = fn () => collect(app(DashboardService::class)->notifications($vehicle->fresh()))->pluck('key');

        $this->assertContains('old_fuel', $keys());

        $refueling->update(['fuel_type' => 'Super Plus (E5)']);

        $this->assertNotContains('old_fuel', $keys());
    }

    public function test_old_e10_fuel_is_not_made_new_by_topping_up_a_little()
    {
        $vehicle = $this->signInWithVehicle(['tank_capacity' => 50]);

        foreach ([[80, 'Unleaded 95 (E10)', 10], [70, 'Unleaded 95 (E5)', 30], [65, 'Unleaded 95 (E10)', 30], [1, 'Super Plus (E10)', 5]] as [$daysAgo, $fuelType, $amount]) {
            Refueling::factory()->for($vehicle)->create([
                'date' => now()->subDays($daysAgo)->toDateString(),
                'fuel_type' => $fuelType,
                'amount' => $amount,
                'charge_time' => null,
            ]);
        }

        // 5 + 30 liters is no full tank yet: the fuel dates from 70 days ago, E5 included.
        $this->assertSame(0, $vehicle->fresh()->fuel_status['time']);

        Refueling::factory()->for($vehicle)->create([
            'date' => now()->toDateString(),
            'fuel_type' => 'Unleaded 95 (E10)',
            'amount' => 20,
            'charge_time' => null,
        ]);

        // 20 + 5 + 30 liters fill the tank: the fuel dates from 65 days ago.
        $this->assertSame(0, $vehicle->fresh()->fuel_status['time']);

        Refueling::factory()->for($vehicle)->create([
            'date' => now()->toDateString(),
            'fuel_type' => 'Unleaded 95 (E10)',
            'amount' => 30,
            'charge_time' => null,
        ]);

        // 30 + 20 liters today fill the tank: the fuel is new.
        $this->assertGreaterThan(55, $vehicle->fresh()->fuel_status['time']);
    }

    public function test_old_e10_fuel_is_not_reported_before_a_full_tank_was_refueled_in_all()
    {
        // A new vehicle came with fuel of its own.
        $vehicle = $this->signInWithVehicle(['tank_capacity' => 50]);

        foreach ([[90, 20], [70, 25]] as [$daysAgo, $amount]) {
            Refueling::factory()->for($vehicle)->create([
                'date' => now()->subDays($daysAgo)->toDateString(),
                'fuel_type' => 'Unleaded 95 (E10)',
                'amount' => $amount,
                'charge_time' => null,
            ]);
        }

        $keys = fn () => collect(app(DashboardService::class)->notifications($vehicle->fresh()))->pluck('key');

        $this->assertSame([], $vehicle->fresh()->fuel_status);
        $this->assertNotContains('old_fuel', $keys());

        // 5 liters more make a full tank in all: from the first refueling on.
        Refueling::factory()->for($vehicle)->create([
            'date' => now()->subDays(65)->toDateString(),
            'fuel_type' => 'Unleaded 95 (E10)',
            'amount' => 5,
            'charge_time' => null,
        ]);

        $this->assertContains('old_fuel', $keys());
    }

    public function test_without_a_tank_capacity_the_latest_refueling_dates_the_fuel()
    {
        $vehicle = $this->signInWithVehicle(['tank_capacity' => null]);

        foreach ([[70, 40], [1, 5]] as [$daysAgo, $amount]) {
            Refueling::factory()->for($vehicle)->create([
                'date' => now()->subDays($daysAgo)->toDateString(),
                'fuel_type' => 'Unleaded 95 (E10)',
                'amount' => $amount,
                'charge_time' => null,
            ]);
        }

        $this->assertGreaterThan(55, $vehicle->fresh()->fuel_status['time']);
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

    public function test_the_detours_are_only_calculated_and_shown_for_the_fuels_of_the_vehicle()
    {
        // signInWithVehicle(): Unleaded 95 (E10) and Super Plus (E5), registered in the Netherlands.
        $vehicle = $this->signInWithVehicle();
        Refueling::factory()->for($vehicle)->create(['total_price' => 60, 'mileage_begin' => 1000, 'mileage_end' => 1500, 'charge_time' => null]);

        $prices = [
            ['netherlands', 'Unleaded 95 (E10)', 2.40], ['germany', 'Unleaded 95 (E10)', 2.10],
            ['netherlands', 'LPG', 1.00], ['germany', 'LPG', 0.80],
        ];

        foreach ($prices as [$country, $fuelType, $price]) {
            FuelPrice::create(['date' => now()->toDateString(), 'country' => $country, 'fuel_type' => $fuelType, 'price' => $price]);
        }

        (new CalculateFuelDetourAggregates)->handle(
            array_map(fn (array $price) => ['date' => now()->toDateString(), 'country' => $price[0], 'fuel_type' => $price[1], 'price' => $price[2]], $prices),
            fn ($data) => $data,
        );

        $germany = collect(app(DashboardService::class)->fuelPricesAbroad($vehicle->fresh())['countries'])->firstWhere('key', 'germany')['prices'];

        $this->assertGreaterThan(0, $germany['Unleaded 95 (E10)']['detourFuel']);
        // A gasoline vehicle cannot run on LPG: no detour, and none stored.
        $this->assertNull($germany['LPG']['detourFuel']);
        $this->assertNull($germany['LPG']['detourAll']);
        $this->assertSame(1, FuelDetourAggregate::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_a_detour_that_was_not_calculated_is_no_break_even_and_one_not_worth_it_is_zero()
    {
        $vehicle = $this->signInWithVehicle();

        $germany = FuelPrice::create(['date' => now()->toDateString(), 'country' => 'germany', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => 2.50]);
        FuelPrice::create(['date' => now()->toDateString(), 'country' => 'belgium', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => 2.10]);
        FuelDetourAggregate::create(['vehicle_id' => $vehicle->id, 'fuel_price_id' => $germany->id, 'max_detour_only_fuel_costs' => 0, 'max_detour_all_costs' => 0]);

        $countries = collect(app(DashboardService::class)->fuelPricesAbroad($vehicle)['countries'])->keyBy('key');

        // Germany is more expensive: not worth any detour, so 0 km.
        $this->assertSame(0, (int) $countries['germany']['prices']['Unleaded 95 (E10)']['detourFuel']);
        $this->assertNotNull($countries['germany']['prices']['Unleaded 95 (E10)']['detourAll']);
        // Not calculated for Belgium: no break-even line at all.
        $this->assertNull($countries['belgium']['prices']['Unleaded 95 (E10)']['detourFuel']);
        $this->assertNull($countries['belgium']['prices']['Unleaded 95 (E10)']['detourAll']);
    }

    /**
     * Unleaded 95 (E10) at home and in Germany, as the import would have stored it.
     *
     * @return list<array{date: string, country: string, fuel_type: string, price: float}>
     */
    private function storeDetourPrices(): array
    {
        $data = [];

        foreach (['netherlands' => 2.40, 'germany' => 2.10] as $country => $price) {
            FuelPrice::create(['date' => now()->toDateString(), 'country' => $country, 'fuel_type' => 'Unleaded 95 (E10)', 'price' => $price]);
            $data[] = ['date' => now()->toDateString(), 'country' => $country, 'fuel_type' => 'Unleaded 95 (E10)', 'price' => $price];
        }

        return $data;
    }

    public function test_the_detours_are_only_calculated_for_drivable_vehicles_unless_one_is_asked_for()
    {
        $drivable = $this->signInWithVehicle();
        $suspended = $this->signInWithVehicle(['status' => 'suspended']);

        foreach ([$drivable, $suspended] as $vehicle) {
            Refueling::factory()->for($vehicle)->create(['total_price' => 60, 'mileage_begin' => 1000, 'mileage_end' => 1500, 'charge_time' => null]);
        }

        $data = $this->storeDetourPrices();
        $aggregates = fn ($vehicle) => FuelDetourAggregate::where('vehicle_id', $vehicle->id)->count();

        (new CalculateFuelDetourAggregates)->handle($data, fn ($data) => $data);

        $this->assertSame(1, $aggregates($drivable));
        $this->assertSame(0, $aggregates($suspended));

        // Asked for by hand, the suspended one counts as well.
        (new CalculateFuelDetourAggregates($suspended->id))->handle($data, fn ($data) => $data);

        $this->assertSame(1, $aggregates($suspended));
    }

    public function test_refreshing_by_hand_calculates_the_detours_of_the_current_vehicle_whatever_its_status()
    {
        $vehicle = $this->signInWithVehicle(['status' => 'suspended']);
        Refueling::factory()->for($vehicle)->create(['total_price' => 60, 'mileage_begin' => 1000, 'mileage_end' => 1500, 'charge_time' => null]);
        $this->storeDetourPrices();

        // The prices were just refreshed: the detours come from the prices already there.
        Cache::put('fuel-prices:refreshing', true, now()->addMinutes(5));

        $this->post(route('fuel-prices.refresh'))->assertRedirect();

        $this->assertSame(1, FuelDetourAggregate::where('vehicle_id', $vehicle->id)->count());
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
