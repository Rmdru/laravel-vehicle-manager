<?php

namespace Tests\Feature;

use App\Ai\Agents\EnergyPriceEstimator;
use App\Ai\Agents\PowertrainAlternativesAdvisor;
use App\Models\FuelPrice;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\PowertrainComparison;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Services\PowertrainComparisonFacts;
use App\Services\PowertrainComparisonService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class PowertrainComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-24 12:00:00');
        // A single key, whatever .env holds.
        config(['ai.providers.gemini.key' => 'test-key']);
        Sleep::fake();
        EnergyPriceEstimator::fake([['electricity_ac_kwh' => 0.45, 'electricity_dc_kwh' => 0.75, 'cng_kg' => 2.2, 'hydrogen_kg' => 18, 'adblue_l' => 1.5]]);
    }

    /**
     * The estimates of a car, with what differs.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function estimates(array $overrides = []): array
    {
        return [
            'build_year' => 2021,
            'power_hp' => 150,
            'fuel_per_100km' => 6.0,
            'electricity_kwh_per_100km' => 0,
            'wltp_electric_range_km' => 0,
            'tank_capacity' => 50,
            'gasoline_tank_l' => 0,
            'price_now_eur' => 18000,
            'depreciation_per_year_eur' => 2100,
            'maintenance_per_year_eur' => 480,
            'insurance_per_year_eur' => 600,
            'road_tax_per_year_eur' => 720,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function alternative(string $powertrain, array $overrides = []): array
    {
        return [
            'powertrain' => $powertrain,
            'brand' => 'Volkswagen',
            'model' => 'Golf',
            'version' => $powertrain,
            ...$this->estimates($overrides),
            'reason' => 'Dezelfde auto met een andere aandrijving.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'current' => $this->estimates(),
            'alternatives' => [
                $this->alternative('electricity', ['fuel_per_100km' => 0, 'tank_capacity' => 0, 'electricity_kwh_per_100km' => 18, 'wltp_electric_range_km' => 400]),
                $this->alternative('gasoline_plugin_hybrid', ['fuel_per_100km' => 6, 'tank_capacity' => 40, 'electricity_kwh_per_100km' => 18, 'wltp_electric_range_km' => 70]),
                $this->alternative('diesel_adblue', ['fuel_per_100km' => 4.5]),
            ],
        ];
    }

    /**
     * A Golf of 2021 bought in 2023, driven 1.000 km a month on 6.5 l/100 km,
     * insured for € 50 a month.
     */
    private function vehicle(array $attributes = []): Vehicle
    {
        $vehicle = $this->signInWithVehicle([
            'brand' => array_search('Volkswagen', config('vehicles.brands'), true),
            'model' => 'Golf',
            'version' => '1.5 eTSI Life',
            'engine' => '1.5 eTSI 150 pk',
            'construction_date' => '2021-03-12',
            'purchase_date' => '2023-04-01',
            'purchase_price' => 30000,
            'mileage_start' => 20000,
            'mileage_latest' => 62000,
            ...$attributes,
        ]);
        $vehicle->user->forceFill(['last_login_at' => now()])->save();

        foreach (range(1, 12) as $month) {
            Refueling::factory()->for($vehicle)->create([
                'date' => today()->subMonths(13 - $month)->toDateString(),
                'fuel_type' => 'Unleaded 95 (E10)',
                'amount' => 65,
                'mileage_begin' => 50000 + ($month - 1) * 1000,
                'mileage_end' => 50000 + $month * 1000,
                'fuel_consumption' => 6.5,
                'charge_time' => null,
            ]);
        }

        Insurance::factory()->for($vehicle)->create(['start_date' => '2026-01-01', 'end_date' => null, 'price' => 50, 'type' => 'wa']);
        FuelPrice::query()->create(['date' => '2026-10-20', 'country' => 'netherlands', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => 2.0]);
        FuelPrice::query()->create(['date' => '2026-10-20', 'country' => 'netherlands', 'fuel_type' => 'Diesel', 'price' => 1.8]);

        return $vehicle->fresh();
    }

    public function test_the_use_and_the_costs_come_from_the_records_of_the_vehicle()
    {
        $usage = app(PowertrainComparisonFacts::class)->usage($this->vehicle());

        $this->assertSame(12000, $usage['km_per_year']);
        $this->assertFalse($usage['km_estimated']);
        $this->assertEquals(6.5, $usage['consumption']);
        $this->assertEquals(['Unleaded 95 (E10)' => 780], $usage['fuel_amounts']);
        $this->assertEquals(600, $usage['insurance_per_year']);
        $this->assertNull($usage['road_tax_per_year']);
        // No maintenance recorded at all: the records say nothing about its costs.
        $this->assertNull($usage['maintenance_per_year']);
    }

    public function test_the_maintenance_costs_only_count_when_every_year_has_one()
    {
        $vehicle = $this->vehicle();
        Maintenance::factory()->for($vehicle)->create(['date' => '2025-06-01', 'total_price' => 600, 'apk' => false]);

        $this->assertNull(app(PowertrainComparisonFacts::class)->usage($vehicle)['maintenance_per_year']);

        Maintenance::factory()->for($vehicle)->create(['date' => '2024-06-01', 'total_price' => 400, 'apk' => false]);
        Maintenance::factory()->for($vehicle)->create(['date' => '2026-06-01', 'total_price' => 500, 'apk' => false]);

        // Over the last three years (of the three and a half it is owned): three paid maintenances.
        $this->assertEqualsWithDelta(1500 / (today()->diffInDays(today()->subYears(3), true) / 365), app(PowertrainComparisonFacts::class)->usage($vehicle)['maintenance_per_year'], 0.01);
    }

    public function test_a_price_comes_from_the_feed_then_the_refuelings_then_the_estimate()
    {
        $vehicle = $this->vehicle();
        $other = Vehicle::factory()->for($vehicle->user)->create(['powertrain' => 'electricity']);
        Refueling::factory()->for($other)->create(['fuel_type' => 'Electricity DC', 'unit_price' => 0.69, 'date' => '2026-10-01', 'charge_time' => null]);
        // A typo: per kWh no one pays that.
        Refueling::factory()->for($other)->create(['fuel_type' => 'Hydrogen', 'unit_price' => 1.9, 'date' => '2026-10-01', 'charge_time' => null]);
        app(PowertrainComparisonFacts::class)->estimatePrices('netherlands');

        $prices = app(PowertrainComparisonFacts::class)->prices($vehicle);

        $this->assertSame(['price' => 2.0, 'source' => 'feed', 'date' => '2026-10-20'], $prices['Unleaded 95 (E10)']);
        $this->assertSame(['price' => 0.69, 'source' => 'refuelings', 'date' => null], $prices['Electricity DC']);
        $this->assertSame(['price' => 0.45, 'source' => 'estimate', 'date' => null], $prices['Electricity AC']);
        $this->assertSame(['price' => 18.0, 'source' => 'estimate', 'date' => null], $prices['Hydrogen']);
        $this->assertSame('fallback', $prices['LPG']['source']);
    }

    public function test_the_prices_without_a_feed_are_estimated_once_a_quarter()
    {
        $facts = app(PowertrainComparisonFacts::class);

        $this->assertTrue($facts->estimatePrices('netherlands'));
        $this->assertFalse($facts->estimatePrices('netherlands'));

        $this->travel(config('powertrain_comparison.prices_refresh_after') + 1)->days();

        $this->assertTrue($facts->estimatePrices('netherlands'));
    }

    public function test_the_alternatives_are_chosen_without_the_own_powertrain_and_what_makes_no_sense()
    {
        $answer = $this->answer();
        $answer['alternatives'][] = $this->alternative('gasoline');
        // No car costs € 100: left out.
        $answer['alternatives'][] = $this->alternative('gasoline_full_hybrid', ['price_now_eur' => 100]);
        PowertrainAlternativesAdvisor::fake([$answer, $answer]);

        $comparison = app(PowertrainComparisonService::class)->generate($this->vehicle(), 'nl');

        $this->assertSame(['gasoline_plugin_hybrid', 'diesel_adblue', 'electricity'], array_column($comparison->alternatives, 'powertrain'));
        $this->assertSame(18000, $comparison->current['price_now_eur']);
        PowertrainAlternativesAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"km_per_year": 12000')
            && str_contains($prompt->prompt, '"insurance_per_year_eur": 600')
            && str_contains($prompt->agent->instructions(), 'Dutch')
            && ! str_contains($prompt->agent->instructions(), '- gasoline: '));
    }

    public function test_an_answer_with_values_that_make_no_sense_is_asked_again_once()
    {
        $answer = $this->answer();
        $wrong = $this->answer();
        $wrong['alternatives'][0]['wltp_electric_range_km'] = 0;
        $prompts = 0;
        PowertrainAlternativesAdvisor::fake(function () use (&$prompts, $answer, $wrong) {
            return ++$prompts === 1 ? $wrong : $answer;
        });

        $comparison = app(PowertrainComparisonService::class)->generate($this->vehicle(), 'nl');

        $this->assertSame(2, $prompts);
        $this->assertCount(3, $comparison->alternatives);
    }

    public function test_when_asking_again_fails_the_first_answer_is_kept()
    {
        $wrong = $this->answer();
        $wrong['alternatives'][0]['wltp_electric_range_km'] = 0;
        $prompts = 0;
        PowertrainAlternativesAdvisor::fake(function () use (&$prompts, $wrong) {
            if (++$prompts > 1) {
                throw RateLimitedException::forProvider('gemini');
            }

            return $wrong;
        });

        $comparison = app(PowertrainComparisonService::class)->generate($this->vehicle(), 'nl');

        $this->assertSame(['gasoline_plugin_hybrid', 'diesel_adblue'], array_column($comparison->alternatives, 'powertrain'));
    }

    public function test_the_costs_are_calculated_on_the_use_of_the_owner()
    {
        PowertrainAlternativesAdvisor::fake([$this->answer()]);
        $vehicle = $this->vehicle();
        $service = app(PowertrainComparisonService::class);
        $result = $service->calculate($vehicle, $service->generate($vehicle, 'nl'));
        $cars = collect($result['cars'])->keyBy('key');

        $this->assertSame(12000, $result['kmPerYear']);
        // 6.5 against the usual 6.0 of the model.
        $this->assertEquals(round(6.5 / 6.0, 2), $result['drivingStyle']);

        // The vehicle itself: what it refueled at today's price, and its own insurance.
        $own = $cars['current'];
        $this->assertEqualsWithDelta(780 * 2.0, $own['costs']['fuel']['year'], 0.01);
        $this->assertFalse($own['costs']['fuel']['estimated']);
        $this->assertEquals(['year' => 600, 'estimated' => false], $own['costs']['insurance']);
        $this->assertEquals(['year' => 720, 'estimated' => true], $own['costs']['road_tax']);
        $this->assertSame(['total' => 769, 'electric' => null, 'fuel' => 769], $own['range']);
        $this->assertEquals(30000, $own['purchasePrice']);

        // Electric: 3/5 of the WLTP range, and fast charging by the kilometers and that range.
        $electric = $cars['electricity'];
        $this->assertSame(240, $electric['range']['total']);
        $fast = round(min(0.85, max(0.05, (0.15 + 0.10 * 2000 / 10000) * 300 / 240)), 2);
        $this->assertEquals($fast, $electric['consumption']['fastShare']);
        $kwh = round(18 * 6.5 / 6.0, 1);
        $this->assertEqualsWithDelta(12000 * $kwh / 100 * ($fast * 0.75 + (1 - $fast) * 0.45), $electric['costs']['fuel']['year'], 0.01);

        // Plug-in hybrid: electric as far as its range covers a day, on the days it is charged.
        $hybrid = $cars['gasoline_plugin_hybrid'];
        $this->assertSame(['total' => 42 + 615, 'electric' => 42, 'fuel' => 615], $hybrid['range']);
        // 40 km a day, within its range of 42 km: on 60% of the days it is charged.
        $this->assertEquals(0.6, $hybrid['consumption']['electricShare']);

        // A diesel with AdBlue.
        $diesel = $cars['diesel_adblue'];
        $liters = round(4.5 * 6.5 / 6.0, 2);
        $this->assertEqualsWithDelta(12000 * ($liters / 100 * 1.8 + 0.12 / 100 * 1.5), $diesel['costs']['fuel']['year'], 0.01);
        $this->assertTrue($diesel['costs']['maintenance']['estimated']);
    }

    public function test_the_power_of_the_vehicle_is_its_own_not_the_estimate()
    {
        // The model takes the 1.5 TSI for the one of 150 hp.
        PowertrainAlternativesAdvisor::fake([$this->answer()]);
        $vehicle = $this->vehicle(['power_hp' => 130]);
        $service = app(PowertrainComparisonService::class);

        $cars = $service->calculate($vehicle, $service->generate($vehicle, 'en'))['cars'];

        $this->assertSame(130, $cars[0]['powerHp']);
        $this->assertSame(150, $cars[1]['powerHp']);
        PowertrainAlternativesAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"power_hp": 130'));
    }

    public function test_the_alternatives_are_chosen_in_the_body_type_of_the_vehicle()
    {
        $answer = $this->answer();
        $answer['current']['body_type'] = 'hatchback';
        $answer['alternatives'][0]['body_type'] = 'station_wagon';
        // Not one of the body types: none shown.
        $answer['alternatives'][1]['body_type'] = 'limousine';
        PowertrainAlternativesAdvisor::fake([$answer, $answer]);
        $service = app(PowertrainComparisonService::class);

        // Unknown: the body the model took the vehicle for.
        $vehicle = $this->vehicle();
        $cars = collect($service->calculate($vehicle, $service->generate($vehicle, 'en'))['cars'])->keyBy('key');

        $this->assertSame('Hatchback', $cars['current']['bodyType']);
        $this->assertSame('Station wagon', $cars['electricity']['bodyType']);
        $this->assertNull($cars['gasoline_plugin_hybrid']['bodyType']);
        PowertrainAlternativesAdvisor::assertPrompted(fn (AgentPrompt $prompt) => ! str_contains($prompt->prompt, 'body_type')
            && str_contains($prompt->agent->instructions(), 'station_wagon (station wagon (estate))'));

        // Filled in: the model is told, and the vehicle shows it.
        $vehicle->update(['body_type' => 'station_wagon']);
        $comparison = $service->generate($vehicle, 'en');

        $this->assertSame('Station wagon', $service->calculate($vehicle, $comparison)['cars'][0]['bodyType']);
        PowertrainAlternativesAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"body_type": "station wagon (estate)"'));
    }

    public function test_the_dashboard_chooses_the_alternatives_once_and_then_shows_them()
    {
        PowertrainAlternativesAdvisor::fake([$this->answer()]);
        $this->vehicle();
        $this->withoutDefer();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('comparison', fn (Assert $reload) => $reload
                    ->where('powertrainComparison.comparison', null)
                    ->etc()));

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('comparison', fn (Assert $reload) => $reload
                    ->where('powertrainComparison.pending', false)
                    ->where('powertrainComparison.comparison.cars.0.key', 'current')
                    ->where('powertrainComparison.comparison.cars.0.brand', 'Volkswagen')
                    ->where('powertrainComparison.comparison.cars.1.key', 'gasoline_plugin_hybrid')
                    ->where('powertrainComparison.comparison.cars.1.reason', 'Dezelfde auto met een andere aandrijving.')
                    ->etc()));

        $this->assertSame(1, PowertrainComparison::count());
    }

    public function test_a_failure_shows_a_message_and_is_not_retried_on_every_visit()
    {
        $tries = 0;
        PowertrainAlternativesAdvisor::fake(function () use (&$tries) {
            $tries++;

            throw RateLimitedException::forProvider('gemini');
        });
        $vehicle = $this->vehicle();
        $this->withoutDefer();
        $service = app(PowertrainComparisonService::class);

        $service->forDashboard($vehicle);
        $data = $service->forDashboard($vehicle);

        // The model and its fallback, once.
        $this->assertSame(2, $tries);
        $this->assertTrue($data['failed']);
        $this->assertFalse($data['canChoose']);
    }

    public function test_changing_what_the_alternatives_depend_on_has_them_chosen_again()
    {
        PowertrainAlternativesAdvisor::fake([$this->answer(), $this->answer()]);
        $vehicle = $this->vehicle(['powertrain' => 'gasoline']);
        $this->withoutDefer();
        app(PowertrainComparisonService::class)->generate($vehicle, 'en');
        $service = app(PowertrainComparisonService::class);

        $this->assertFalse($service->outdated($vehicle, 'en'));

        $vehicle->update(['mileage_latest' => 63000]);
        $this->assertFalse($service->outdated($vehicle, 'en'));

        $vehicle->update(['body_type' => 'hatchback']);
        $this->assertTrue($service->outdated($vehicle, 'en'));
        $vehicle->update(['body_type' => null]);
        $this->assertFalse($service->outdated($vehicle, 'en'));

        $vehicle->update(['power_hp' => 130]);
        $this->assertTrue($service->outdated($vehicle, 'en'));
        $vehicle->update(['power_hp' => null]);
        $this->assertFalse($service->outdated($vehicle, 'en'));

        $vehicle->update(['engine' => '1.0 TSI 110 pk']);
        $this->assertTrue($service->outdated($vehicle, 'en'));

        $service->chooseLater($vehicle->fresh(), 'en', force: true);

        $this->assertFalse($service->outdated($vehicle->fresh(), 'en'));
    }

    public function test_the_costs_can_be_calculated_again_by_hand()
    {
        PowertrainAlternativesAdvisor::fake([$this->answer()]);
        $vehicle = $this->vehicle();
        app(PowertrainComparisonService::class)->generate($vehicle, 'en');
        $fuel = fn () => app(PowertrainComparisonService::class)->forDashboard($vehicle)['comparison']['cars'][0]['costs']['fuel']['year'];

        $before = $fuel();
        FuelPrice::query()->create(['date' => '2026-10-23', 'country' => 'netherlands', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => 2.5]);

        // Kept for the day.
        $this->assertEquals($before, $fuel());

        $this->post(route('powertrain-comparison.refresh'))->assertRedirect();

        $this->assertEqualsWithDelta(780 * 2.5, $fuel(), 0.01);
    }

    public function test_the_alternatives_can_be_chosen_again_by_hand_but_not_within_minutes()
    {
        $prompts = 0;
        PowertrainAlternativesAdvisor::fake(function () use (&$prompts) {
            $prompts++;

            return $this->answer();
        });
        $this->vehicle();
        $this->withoutDefer();

        $this->post(route('powertrain-comparison.store'))->assertRedirect();
        $this->post(route('powertrain-comparison.store'))->assertRedirect();

        $this->assertSame(1, $prompts);
        $this->assertSame(1, PowertrainComparison::count());

        $this->travel(config('powertrain_comparison.regenerate_after') + 1)->minutes();
        $this->post(route('powertrain-comparison.store'))->assertRedirect();

        $this->assertSame(2, $prompts);
    }

    public function test_the_daily_run_only_asks_for_vehicles_that_need_it()
    {
        $this->assertSame('45 2 * * *', collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'generate:powertrain-comparisons'))?->expression);

        $prompts = 0;
        PowertrainAlternativesAdvisor::fake(function () use (&$prompts) {
            $prompts++;

            return $this->answer();
        });
        $vehicle = $this->vehicle();

        $this->artisan('generate:powertrain-comparisons')->assertExitCode(0);
        $this->artisan('generate:powertrain-comparisons')->assertExitCode(0);

        $this->assertSame(1, $prompts);
        $this->assertSame(1, $vehicle->powertrainComparisons()->count());
    }

    public function test_nothing_is_asked_for_an_owner_who_did_not_log_in_recently()
    {
        $prompts = 0;
        PowertrainAlternativesAdvisor::fake(function () use (&$prompts) {
            $prompts++;

            return $this->answer();
        });
        $vehicle = $this->vehicle();
        $vehicle->user->forceFill(['last_login_at' => now()->subMonths(2)])->save();
        $this->withoutDefer();

        $this->artisan('generate:powertrain-comparisons')->assertExitCode(0);
        $data = app(PowertrainComparisonService::class)->forDashboard($vehicle->fresh());
        app(PowertrainComparisonService::class)->chooseLater($vehicle->fresh(), 'en', force: true);

        $this->assertSame(0, $prompts);
        $this->assertFalse($data['pending']);
    }

    public function test_every_vehicle_shows_the_prices_it_is_calculated_with()
    {
        PowertrainAlternativesAdvisor::fake([$this->answer()]);
        $vehicle = $this->vehicle();
        $service = app(PowertrainComparisonService::class);
        $cars = collect($service->calculate($vehicle, $service->generate($vehicle, 'nl'))['cars'])->keyBy('key');

        $this->assertSame([['type' => 'Unleaded 95 (E10)', 'label' => 'Unleaded 95 (E10)', 'price' => 2.0, 'unit' => 'l', 'estimated' => false]], $cars['current']['prices']);
        $this->assertSame(['Electricity AC', 'Electricity DC'], array_column($cars['electricity']['prices'], 'type'));
        $this->assertSame([true, true], array_column($cars['electricity']['prices'], 'estimated'));
        $this->assertSame(['Unleaded 95 (E10)', 'Electricity AC'], array_column($cars['gasoline_plugin_hybrid']['prices'], 'type'));
        $this->assertSame('Diesel', $cars['diesel_adblue']['prices'][0]['type']);
    }
}
