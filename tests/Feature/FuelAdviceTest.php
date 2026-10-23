<?php

namespace Tests\Feature;

use App\Ai\Agents\FuelAdvisor;
use App\Models\FuelPrice;
use App\Models\Refueling;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuelAdviceFacts;
use App\Services\FuelAdviceService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\WebFetch;
use RuntimeException;
use Tests\TestCase;

class FuelAdviceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-17 12:00:00');
        Sleep::fake();
        // A single key, whatever .env holds; the tests of the rotation set their own.
        config(['ai.providers.gemini.key' => 'test-key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(array $overrides = []): array
    {
        return [
            'action' => 'wait',
            'country' => 'netherlands',
            'recommended_date' => '2026-10-20',
            'confidence' => 'medium',
            'summary' => 'Wacht tot maandag met tanken.',
            'advice' => "De prijzen dalen.\n\n- Maandag is goedkoper.\n- Je tank is nog **halfvol**.",
            'reasons' => ['De prijzen dalen.', 'Maandag is goedkoper.'],
            ...$overrides,
        ];
    }

    private function prices(): void
    {
        foreach (range(0, 13) as $day) {
            FuelPrice::create(['country' => 'netherlands', 'fuel_type' => 'Unleaded 95 (E10)', 'date' => today()->subDays($day), 'price' => 2.40 - $day * 0.01]);
            FuelPrice::create(['country' => 'germany', 'fuel_type' => 'Unleaded 95 (E5)', 'date' => today()->subDays($day), 'price' => 2.10]);
        }
    }

    /**
     * Tanks of 40 liters, 500 km and 10 days apart: 50 km a day, 5 l/100km.
     * The last one was the Super Plus of "1 in 4 times", unless all are regular.
     */
    private function refuelings(Vehicle $vehicle, bool $regular = false): void
    {
        foreach ([[30, 9500], [20, 10000], [10, 10500], [0, 11000]] as [$daysAgo, $mileage]) {
            Refueling::factory()->for($vehicle)->create([
                'date' => today()->subDays($daysAgo)->toDateString(),
                'fuel_type' => $daysAgo === 0 && ! $regular ? 'Super Plus (E5)' : 'Unleaded 95 (E10)',
                // One in four is premium: not less than the 25% of "1 in 4 times".
                'amount' => 40,
                'unit_price' => 2.40,
                // € 0.12 per kilometer.
                'total_price' => 60,
                'mileage_begin' => $mileage - 500,
                'mileage_end' => $mileage,
                'percentage' => 100,
                'fuel_consumption' => 5,
                'charge_time' => null,
            ]);
        }
    }

    public function test_the_next_refueling_is_expected_when_the_tank_reaches_its_reserve()
    {
        $vehicle = $this->signInWithVehicle(['tank_capacity' => 50]);
        $this->refuelings($vehicle);

        $next = app(FuelAdviceFacts::class)->nextRefueling($vehicle);

        // 50 l minus a reserve of 15% is 42.5 l: 850 km, 17 days at 50 km a day.
        $this->assertSame(50.0, $next['average_km_per_day']);
        $this->assertSame(850, $next['estimated_range_km_until_reserve']);
        $this->assertSame('2026-11-03', $next['expected_date']);
        $this->assertSame(100, $next['estimated_tank_percentage_now']);
        $this->assertFalse($next['overdue']);
    }

    public function test_the_facts_compare_the_fuel_with_a_comparable_one_abroad()
    {
        $vehicle = $this->signInWithVehicle();
        $this->prices();
        $this->refuelings($vehicle);

        $facts = app(FuelAdviceFacts::class)->for($vehicle);

        $this->assertSame('Unleaded 95 (E10)', $facts['fuel_type']);
        $this->assertSame('netherlands', $facts['home_country']);
        $this->assertSame(['germany', 'netherlands'], array_column($facts['countries'], 'country'));
        $this->assertSame('Unleaded 95 (E5)', $facts['countries'][0]['fuel_type']);
        $this->assertEquals(15.0, $facts['countries'][0]['savings_per_full_tank_eur']);
        $this->assertEquals(0.07, $facts['countries'][1]['change_7_days']);
    }

    public function test_without_a_city_the_driver_is_taken_to_live_in_the_country_of_registration()
    {
        FuelAdvisor::fake([$this->answer(['action' => 'refuel_abroad', 'country' => 'germany'])]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();
        $this->refuelings($vehicle);

        $advice = app(FuelAdviceService::class)->generate($vehicle, 'nl');

        $this->assertSame(['city' => 'unknown', 'country' => 'netherlands', 'known' => false], $advice->facts['driver_location']);
        $this->assertFalse($advice->facts['countries'][0]['worth_the_detour']);
        // Refueling abroad is no option: not to the model, and not when it answers so anyway.
        $this->assertSame('anytime', $advice->action);
        $this->assertNull($advice->country);
        FuelAdvisor::assertPrompted(fn (AgentPrompt $prompt) => ! in_array('refuel_abroad', $prompt->agent->actions(), true)
            && $prompt->agent->countries() === ['netherlands']);
    }

    public function test_refueling_abroad_is_worth_it_from_a_city_near_the_border()
    {
        FuelAdvisor::fake([$this->answer(['action' => 'refuel_abroad', 'country' => 'germany'])]);

        Http::swap(new Factory);
        Http::fake(['*geocoding*' => Http::response(['results' => [['latitude' => 51.37, 'longitude' => 6.17, 'country_code' => 'NL']]])]);

        // Venlo, some 8 km by road from Kaldenkirchen.
        $vehicle = $this->signInWithVehicle();
        $vehicle->user->update(['city' => 'Venlo', 'latitude' => 51.37, 'longitude' => 6.17]);
        $this->prices();
        $this->refuelings($vehicle);

        $advice = app(FuelAdviceService::class)->generate($vehicle->refresh(), 'en');
        $germany = $advice->facts['countries'][0];

        $this->assertSame(['city' => 'Venlo', 'country' => 'netherlands', 'known' => true], $advice->facts['driver_location']);
        $this->assertSame(8, $germany['distance_from_driver_km']);
        // € 15 cheaper per tank, minus 2 x 8 km at € 0.12 per kilometer.
        $this->assertEquals(13.08, $germany['net_savings_per_full_tank_eur']);
        $this->assertTrue($germany['worth_the_detour']);
        $this->assertSame('refuel_abroad', $advice->action);
        $this->assertSame('germany', $advice->country);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('fuelAdvice.advice.bestPrice.distance', '± 8 km')
                    ->where('fuelAdvice.advice.bestPrice.savings', '€ 13,08')
                    ->etc()));
    }

    public function test_refueling_abroad_is_not_worth_it_from_far_away()
    {
        // Amsterdam is far from every border.
        $vehicle = $this->signInWithVehicle();
        $vehicle->user->update(['city' => 'Amsterdam', 'latitude' => 52.37, 'longitude' => 4.90]);
        $this->prices();
        $this->refuelings($vehicle);

        $germany = app(FuelAdviceFacts::class)->for($vehicle->refresh())['countries'][0];

        $this->assertGreaterThan(120, $germany['distance_from_driver_km']);
        $this->assertLessThan(0, $germany['net_savings_per_full_tank_eur']);
        $this->assertFalse($germany['worth_the_detour']);
    }

    /**
     * Without the Super Plus of "1 in 4 times", the advice is about E10: it has
     * been in the tank for 40 days, so the fuel is getting old.
     *
     * @return array<string, mixed>
     */
    private function germanyWithOldFuelFrom(string $city, float $latitude, float $longitude, bool $oldFuel = true): array
    {
        $vehicle = $this->signInWithVehicle(['notifications' => [['periodic_super_plus' => false, 'old_fuel' => $oldFuel]]]);
        $vehicle->user->update(['city' => $city, 'latitude' => $latitude, 'longitude' => $longitude]);
        $this->refuelings($vehicle, regular: true);
        $this->travel(40)->days();
        $this->prices();

        return collect(app(FuelAdviceFacts::class)->for($vehicle->refresh())['countries'])->firstWhere('country', 'germany');
    }

    public function test_with_old_fuel_in_the_tank_the_first_kilometers_of_the_detour_are_free()
    {
        FuelAdvisor::fake([$this->answer(['action' => 'refuel_abroad', 'country' => 'germany'])]);

        // Amersfoort is about 90 km from Germany by road: of those, 50 are free.
        $germany = $this->germanyWithOldFuelFrom('Amersfoort', 52.156, 5.387);

        $this->assertSame(50, $germany['free_km_one_way_for_old_fuel']);
        // € 15 cheaper on a full tank, minus 2 × (90 - 50) km at € 0.12.
        $this->assertSame(round(2 * ($germany['distance_from_driver_km'] - 50) * 0.12, 2), $germany['detour_fuel_costs_eur']);
        $this->assertGreaterThan(0, $germany['net_savings_per_full_tank_eur']);
        $this->assertTrue($germany['worth_the_detour']);

        app(FuelAdviceService::class)->generate(Vehicle::query()->sole(), 'en');

        FuelAdvisor::assertPrompted(fn (AgentPrompt $prompt) => in_array('refuel_abroad', $prompt->agent->actions(), true)
            && str_contains($prompt->agent->instructions(), 'free_km_one_way_for_old_fuel kilometers each way do not count'));
    }

    public function test_without_old_fuel_the_same_detour_is_not_worth_it()
    {
        $germany = $this->germanyWithOldFuelFrom('Amersfoort', 52.156, 5.387, oldFuel: false);

        $this->assertArrayNotHasKey('free_km_one_way_for_old_fuel', $germany);
        $this->assertLessThan(0, $germany['net_savings_per_full_tank_eur']);
        $this->assertFalse($germany['worth_the_detour']);
    }

    public function test_with_old_fuel_in_the_tank_a_country_far_away_is_still_not_worth_it()
    {
        // Amsterdam is more than 120 km from every border.
        $germany = $this->germanyWithOldFuelFrom('Amsterdam', 52.37, 4.90);

        $this->assertGreaterThan(120, $germany['distance_from_driver_km']);
        $this->assertLessThan(0, $germany['net_savings_per_full_tank_eur']);
        $this->assertFalse($germany['worth_the_detour']);
    }

    public function test_old_fuel_is_left_out_when_its_notification_is_off()
    {
        $vehicle = $this->signInWithVehicle(['notifications' => [['old_fuel' => false, 'periodic_super_plus' => false]]]);
        $this->refuelings($vehicle, regular: true);
        $this->travel(40)->days();
        $this->prices();

        $this->assertFalse(app(FuelAdviceFacts::class)->for($vehicle->refresh())['old_fuel']);
    }

    public function test_the_advice_is_about_super_plus_when_it_is_its_turn()
    {
        $vehicle = $this->signInWithVehicle();
        $this->prices();
        FuelPrice::create(['country' => 'netherlands', 'fuel_type' => 'Super Plus (E5)', 'date' => today(), 'price' => 2.70]);
        $this->refuelings($vehicle, regular: true);

        $facts = app(FuelAdviceFacts::class)->for($vehicle);

        $this->assertTrue($facts['super_plus_due']);
        $this->assertSame('Super Plus (E5)', $facts['fuel_type']);
        $this->assertSame('Unleaded 95 (E10)', $facts['usual_fuel_type']);
        $this->assertEquals(2.70, collect($facts['countries'])->firstWhere('country', 'netherlands')['latest_price']);
        // Germany has no Super Plus: the comparable E5.
        $this->assertSame('Unleaded 95 (E5)', collect($facts['countries'])->firstWhere('country', 'germany')['fuel_type']);
    }

    public function test_super_plus_is_left_out_when_its_notification_is_off()
    {
        $vehicle = $this->signInWithVehicle();
        $vehicle->update(['notifications' => [...$vehicle->notifications, 'refueling' => ['old_fuel' => true, 'periodic_super_plus' => false]]]);
        $this->prices();
        $this->refuelings($vehicle, regular: true);

        $facts = app(FuelAdviceFacts::class)->for($vehicle->refresh());

        $this->assertFalse($facts['super_plus_due']);
        $this->assertSame('Unleaded 95 (E10)', $facts['fuel_type']);
    }

    public function test_the_other_key_is_used_when_one_fails()
    {
        config(['ai.providers.gemini.key' => 'key-a, key-b']);
        $used = [];

        FuelAdvisor::fake(function ($prompt, $attachments, $provider) use (&$used) {
            $used[] = $key = $provider->providerCredentials()['key'];

            if ($key === 'key-a') {
                throw RateLimitedException::forProvider('gemini');
            }

            return $this->answer();
        });

        $vehicle = $this->signInWithVehicle();
        $this->prices();

        $advice = app(FuelAdviceService::class)->generate($vehicle, 'nl');

        $this->assertSame('key-b', end($used));
        $this->assertContains(count($used), [1, 2]);
        $this->assertTrue($advice->grounded);
    }

    public function test_a_refused_key_is_not_tried_again_with_less_of_the_web()
    {
        config(['ai.providers.gemini.key' => 'key-a,key-b']);
        $used = [];

        FuelAdvisor::fake(function ($prompt, $attachments, $provider) use (&$used) {
            $used[] = $key = $provider->providerCredentials()['key'];
            // The refused key, and the pages to read, which fail with the other key.
            throw match (true) {
                $key === 'key-a' => new RequestException(new Response(new Psr7Response(401))),
                str_contains($prompt, 'Pages to read') => new RuntimeException('URL context failed'),
                default => new RuntimeException('Done'),
            };
        });

        $vehicle = $this->signInWithVehicle();
        $this->prices();

        try {
            app(FuelAdviceService::class)->generate($vehicle, 'nl');
            $this->fail('No answer was expected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Done', $exception->getMessage());
        }

        // Key A once, key B with the pages and without them.
        $this->assertSame(1, count(array_keys($used, 'key-a')));
        $this->assertSame(2, count(array_keys($used, 'key-b')));
    }

    public function test_the_keys_are_tried_in_random_order()
    {
        config(['ai.providers.gemini.key' => 'key-a,key-b']);
        $first = [];

        FuelAdvisor::fake(function ($prompt, $attachments, $provider) use (&$first) {
            $first[] = $provider->providerCredentials()['key'];

            return $this->answer();
        });

        $vehicle = $this->signInWithVehicle();
        $this->prices();

        foreach (range(1, 30) as $attempt) {
            app(FuelAdviceService::class)->generate($vehicle, 'nl');
        }

        $this->assertEqualsCanonicalizing(['key-a', 'key-b'], array_values(array_unique($first)));
    }

    /**
     * @return array<string, mixed>
     */
    private function refuelingInput(string $date, int $mileage): array
    {
        return [
            'date' => $date,
            'country' => 'netherlands',
            'gas_station' => 'Tango Utrecht',
            'fuel_type' => 'Unleaded 95 (E10)',
            'amount' => 40,
            'percentage' => 100,
            'unit_price' => 2.35,
            'total_price' => 94,
            'mileage_begin' => $mileage - 500,
            'mileage_end' => $mileage,
        ];
    }

    public function test_a_new_refueling_generates_the_advice_again()
    {
        FuelAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle();
        $vehicle->user->update(['language' => 'nl']);
        $this->actingAs($vehicle->user);
        $this->prices();
        $this->refuelings($vehicle);

        $this->post(route('refuelings.store'), $this->refuelingInput('2026-10-17', 11500))->assertSessionHasNoErrors();

        $advice = $vehicle->fuelAdvices()->sole();
        $this->assertSame('nl', $advice->locale);
        $this->assertSame('2026-10-17', $advice->facts['next_refueling']['last_refueling']);
    }

    public function test_an_older_refueling_entered_later_does_not_generate_the_advice_again()
    {
        FuelAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();
        $this->refuelings($vehicle);

        $this->post(route('refuelings.store'), $this->refuelingInput('2026-09-01', 9000))->assertSessionHasNoErrors();

        $this->assertSame(0, $vehicle->fuelAdvices()->count());
        FuelAdvisor::assertNeverPrompted();
    }

    public function test_the_advice_tells_how_the_previous_advice_turned_out()
    {
        FuelAdvisor::fake([$this->answer(['action' => 'wait', 'recommended_date' => '2026-10-15']), $this->answer()]);

        // Three days ago: wait until the 15th, with 2.40 in the Netherlands.
        $this->travelTo('2026-10-14 12:00:00');
        $vehicle = $this->signInWithVehicle();
        $this->prices();
        $this->refuelings($vehicle);
        app(FuelAdviceService::class)->generate($vehicle, 'en');

        // Refueled on the 16th for 2.35, and a new advice after it.
        $this->travelTo('2026-10-17 12:00:00');
        $this->post(route('refuelings.store'), $this->refuelingInput('2026-10-16', 11500))->assertSessionHasNoErrors();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('fuelAdvice.advice.review.followed', true)
                    ->where('fuelAdvice.advice.review.paid', '€ 2,350/l')
                    ->where('fuelAdvice.advice.review.average', '€ 2,400/l')
                    ->where('fuelAdvice.advice.review.difference', '€ 0,050/l')
                    ->where('fuelAdvice.advice.review.cheaper', true)
                    // 40 liters at 5 cents less.
                    ->where('fuelAdvice.advice.review.savings', '€ 2,00')
                    ->etc()));
    }

    public function test_the_facts_tell_how_much_less_the_driver_pays_at_a_gas_station()
    {
        $vehicle = $this->signInWithVehicle();
        $this->prices();

        // The average of the country is 2.40 today and 2.39 yesterday.
        foreach ([[0, 'Tango Utrecht', 2.33], [1, 'Tango Utrecht', 2.34], [0, 'Shell A2', 2.45], [1, 'Shell A2', 2.44], [0, 'Esso once', 2.10]] as [$daysAgo, $station, $price]) {
            Refueling::factory()->for($vehicle)->create([
                'date' => today()->subDays($daysAgo)->toDateString(),
                'country' => 'netherlands',
                'gas_station' => $station,
                'fuel_type' => 'Unleaded 95 (E10)',
                'unit_price' => $price,
                'charge_time' => null,
            ]);
        }

        $stations = app(FuelAdviceFacts::class)->for($vehicle)['own_gas_stations'];

        // A single visit is left out.
        $this->assertSame(['Tango Utrecht', 'Shell A2'], array_column($stations, 'name'));
        $this->assertEquals(-0.06, $stations[0]['average_difference_with_country_average']);
        $this->assertEquals(0.05, $stations[1]['average_difference_with_country_average']);
        $this->assertSame(2, $stations[0]['visits']);
    }

    public function test_the_cheapest_gas_station_is_one_in_the_country_of_the_advice()
    {
        $vehicle = $this->signInWithVehicle();
        $stations = [
            ['name' => 'Aral Aachen', 'country' => 'germany', 'average_difference_with_country_average' => -0.08],
            ['name' => 'Tango Utrecht', 'country' => 'netherlands', 'average_difference_with_country_average' => -0.06],
        ];
        $vehicle->fuelAdvices()->create([...$this->advice(), 'locale' => 'en', 'country' => 'netherlands', 'facts' => ['home_country' => 'netherlands', 'own_gas_stations' => $stations]]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('fuelAdvice.advice.cheapestStation.name', 'Tango Utrecht')
                    ->etc()));
    }

    public function test_an_urgent_advice_is_on_top_of_the_notifications()
    {
        FuelAdvisor::fake([$this->answer(['action' => 'refuel_now', 'confidence' => 'high', 'summary' => 'Tank vandaag nog.'])]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();
        app(FuelAdviceService::class)->generate($vehicle, 'en');

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('notifications.0.key', 'fuel_advice')
                    ->where('notifications.0.text', 'Tank vandaag nog.')
                    ->where('notifications.0.type', 'warning')
                    ->where('notifications.0.opensFuelAdvice', true)
                    // "All is well" no longer is.
                    ->where('notifications', fn ($notifications) => ! collect($notifications)->contains('type', 'success'))
                    ->etc()));
    }

    public function test_an_advice_for_another_day_is_no_notification()
    {
        FuelAdvisor::fake([$this->answer(['action' => 'wait', 'recommended_date' => '2026-10-21'])]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();
        app(FuelAdviceService::class)->generate($vehicle, 'en');

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('notifications', fn ($notifications) => ! collect($notifications)->contains('key', 'fuel_advice'))
                    ->etc()));
    }

    public function test_an_advice_for_tomorrow_is_a_notification()
    {
        FuelAdvisor::fake([$this->answer(['action' => 'wait', 'recommended_date' => '2026-10-18'])]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();
        app(FuelAdviceService::class)->generate($vehicle, 'en');

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('notifications.0.key', 'fuel_advice')
                    ->where('notifications.0.type', 'info')
                    ->etc()));
    }

    public function test_there_are_no_facts_for_a_vehicle_that_does_not_use_fuel()
    {
        $vehicle = $this->signInWithVehicle(['powertrain' => 'electricity']);
        $this->prices();

        $this->assertNull(app(FuelAdviceFacts::class)->for($vehicle));
    }

    public function test_an_advice_is_generated_from_the_facts_and_the_pages_to_read()
    {
        FuelAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();
        $this->refuelings($vehicle);

        $advice = app(FuelAdviceService::class)->generate($vehicle, 'nl');

        $this->assertSame('wait', $advice->action);
        $this->assertSame('netherlands', $advice->country);
        $this->assertSame('2026-10-20', $advice->recommended_date->toDateString());
        $this->assertSame('nl', $advice->locale);
        $this->assertTrue($advice->grounded);
        $this->assertSame('2026-11-03', $advice->facts['next_refueling']['expected_date']);

        FuelAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"fuel_type": "Unleaded 95 (E10)"')
            && str_contains($prompt->prompt, 'https://carbu.com/luxembourg/index.php/prixmaximum')
            && collect($prompt->agent->tools())->contains(fn ($tool) => $tool instanceof WebFetch)
            && str_contains($prompt->agent->instructions(), 'Dutch'));
    }

    public function test_what_the_model_makes_up_is_left_out()
    {
        FuelAdvisor::fake([$this->answer(['action' => 'panic', 'country' => 'atlantis', 'recommended_date' => '2026-10-01', 'confidence' => 'total'])]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();

        $advice = app(FuelAdviceService::class)->generate($vehicle, 'en');

        $this->assertSame('anytime', $advice->action);
        $this->assertNull($advice->country);
        $this->assertSame('2026-10-17', $advice->recommended_date->toDateString());
        $this->assertSame('low', $advice->confidence);
    }

    public function test_the_advice_is_based_on_the_facts_alone_when_reading_the_web_fails()
    {
        $calls = 0;

        FuelAdvisor::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('Quota exceeded');
            }

            return $this->answer();
        });

        $vehicle = $this->signInWithVehicle();
        $this->prices();

        $advice = app(FuelAdviceService::class)->generate($vehicle, 'nl');

        $this->assertSame(2, $calls);
        $this->assertFalse($advice->grounded);
        FuelAdvisor::assertPrompted(fn (AgentPrompt $prompt) => iterator_to_array($prompt->agent->tools()) === []);
    }

    public function test_the_dashboard_shows_the_latest_advice_in_the_language_of_the_user()
    {
        FuelAdvisor::fake([$this->answer(), $this->answer(['summary' => 'Fill up in Germany.'])]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();
        $this->refuelings($vehicle);

        app(FuelAdviceService::class)->generate($vehicle, 'nl');
        $this->travel(1)->minutes();
        app(FuelAdviceService::class)->generate($vehicle, 'en');

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('fuel', fn (Assert $reload) => $reload
                    ->where('fuelAdvice.advice.summary', 'Fill up in Germany.')
                    ->where('fuelAdvice.advice.isCurrent', true)
                    ->where('fuelAdvice.advice.homePrice.price', '€ 2,400/l')
                    ->where('fuelAdvice.canGenerate', false)
                    ->where('fuelPricesChart.expectedRefuelDate', '2026-11-03')
                    ->etc()));
    }

    public function test_without_an_advice_in_the_language_of_the_user_one_can_be_generated()
    {
        $vehicle = $this->signInWithVehicle();
        $vehicle->fuelAdvices()->create([...$this->advice(), 'locale' => 'nl']);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('fuel', fn (Assert $reload) => $reload
                    ->where('fuelAdvice.advice', null)
                    ->where('fuelAdvice.canGenerate', true)
                    ->etc()));
    }

    public function test_an_advice_can_be_generated_by_hand_for_a_vehicle_that_is_not_drivable()
    {
        FuelAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle(['status' => 'suspended']);
        $this->prices();

        $this->from(route('dashboard'))
            ->post(route('fuel-advice.store'))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, $vehicle->fuelAdvices()->where('locale', 'en')->count());
    }

    public function test_an_advice_can_be_generated_by_hand_whenever_the_owner_last_logged_in()
    {
        FuelAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle();
        $vehicle->user->forceFill(['last_login_at' => now()->subYear()])->save();
        $this->prices();

        $this->post(route('fuel-advice.store'));

        $this->assertSame(1, $vehicle->fuelAdvices()->count());
    }

    public function test_an_advice_is_not_generated_by_hand_again_within_minutes()
    {
        FuelAdvisor::fake([$this->answer(), $this->answer()]);

        $vehicle = $this->signInWithVehicle();
        $this->prices();

        $this->post(route('fuel-advice.store'));
        $this->post(route('fuel-advice.store'));
        $this->assertSame(1, $vehicle->fuelAdvices()->count());

        $this->travel(11)->minutes();
        $this->post(route('fuel-advice.store'));
        $this->assertSame(2, $vehicle->fuelAdvices()->count());
    }

    public function test_a_failing_model_is_reported_to_the_user()
    {
        FuelAdvisor::fake(fn () => throw new RuntimeException('Down'));

        $vehicle = $this->signInWithVehicle();
        $this->prices();

        $this->post(route('fuel-advice.store'))->assertSessionHasNoErrors();

        $this->assertSame(0, $vehicle->fuelAdvices()->count());
    }

    public function test_the_daily_run_generates_an_advice_per_drivable_vehicle_in_the_language_of_its_owner()
    {
        FuelAdvisor::fake([$this->answer(), $this->answer(), $this->answer()]);
        $this->prices();

        $german = Vehicle::factory()->for(User::factory()->create(['language' => 'de']))->create(['status' => 'drivable', 'powertrain' => 'gasoline', 'fuel_types' => ['Unleaded 95 (E10)'], 'country_registration' => 'netherlands']);
        $default = Vehicle::factory()->for(User::factory()->create())->create(['status' => 'drivable', 'powertrain' => 'diesel', 'fuel_types' => ['Unleaded 95 (E10)'], 'country_registration' => 'netherlands']);
        $sold = Vehicle::factory()->create(['status' => 'sold', 'powertrain' => 'gasoline']);
        $electric = Vehicle::factory()->create(['status' => 'drivable', 'powertrain' => 'electricity']);
        $done = Vehicle::factory()->for(User::factory()->create(['language' => 'nl']))->create(['status' => 'drivable', 'powertrain' => 'gasoline']);
        $done->fuelAdvices()->create([...$this->advice(), 'locale' => 'nl']);
        $inactive = Vehicle::factory()->for(User::factory()->inactive()->create())->create(['status' => 'drivable', 'powertrain' => 'gasoline', 'fuel_types' => ['Unleaded 95 (E10)'], 'country_registration' => 'netherlands']);

        $this->artisan('generate:fuel-advices')->assertExitCode(0);

        $this->assertSame(['de'], $german->fuelAdvices()->pluck('locale')->all());
        $this->assertSame([config('app.locale')], $default->fuelAdvices()->pluck('locale')->all());
        $this->assertSame(0, $sold->fuelAdvices()->count());
        $this->assertSame(0, $electric->fuelAdvices()->count());
        $this->assertSame(1, $done->fuelAdvices()->count());
        $this->assertSame(0, $inactive->fuelAdvices()->count());
        FuelAdvisor::assertPromptedTimes(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_the_daily_run_is_scheduled_after_the_import_of_the_fuel_prices()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'generate:fuel-advices'));

        $this->assertSame('0 2 * * *', $event?->expression);
    }

    public function test_the_chart_marks_no_expected_refueling_for_a_period_in_the_past()
    {
        $vehicle = $this->signInWithVehicle();
        $this->prices();
        $this->refuelings($vehicle);

        $this->get(route('dashboard', ['dashboard_start' => '2026-10-05', 'dashboard_end' => '2026-10-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('fuel', fn (Assert $reload) => $reload
                    ->where('fuelPricesChart.expectedRefuelDate', null)
                    ->etc()));
    }

    /**
     * @return array<string, mixed>
     */
    private function advice(): array
    {
        return [
            'action' => 'anytime',
            'confidence' => 'low',
            'summary' => 'Tank wanneer je wilt.',
            'body' => 'Geen verschil.',
            'reasons' => [],
            'sources' => [],
            'facts' => [],
            'model' => 'gemini-3.1-flash-lite',
        ];
    }
}
