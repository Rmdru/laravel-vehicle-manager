<?php

namespace Tests\Feature;

use App\Ai\Agents\VehicleAdvisor;
use App\Models\Finding;
use App\Models\Maintenance;
use App\Models\Refueling;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAdvice;
use App\Models\VehicleTip;
use App\Services\VehicleAdviceFacts;
use App\Services\VehicleAdviceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

class VehicleAdviceTest extends TestCase
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
    private function suggestion(array $overrides = []): array
    {
        return [
            'key' => 'timing_belt',
            'category' => 'maintenance',
            'priority' => 'medium',
            'basis' => 'general_knowledge',
            'action' => 'add_maintenance',
            'due_date' => '2027-03-01',
            'title' => 'Laat de distributieriem vervangen',
            'body' => 'Bij deze motor gaat de riem meestal 120.000 km of 6 jaar mee.',
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     * @return array{suggestions: list<array<string, mixed>>}
     */
    private function answer(array $suggestions = []): array
    {
        return ['suggestions' => $suggestions ?: [$this->suggestion()]];
    }

    private function tip(Vehicle $vehicle, string $key, string $status): VehicleTip
    {
        return VehicleTip::create([
            ...$this->suggestion(['key' => $key, 'title' => 'Winter tires', 'due_date' => null]),
            'vehicle_id' => $vehicle->id,
            'status' => $status,
            'locale' => 'en',
        ]);
    }

    public function test_the_facts_tell_what_is_known_about_the_vehicle()
    {
        $vehicle = $this->signInWithVehicle([
            'brand' => 'Volkswagen',
            'model' => 'Golf',
            'engine' => '1.4 TSI',
            'construction_date' => '2014-05-01',
            'mileage_latest' => 182000,
            'rdw_data' => ['vervaldatum_apk_dt' => '2026-11-20T00:00:00.000', 'inrichting' => 'hatchback'],
        ]);
        Maintenance::factory()->for($vehicle)->create([
            'date' => '2026-03-01',
            'mileage' => 175000,
            'type_maintenance' => 'big_maintenance',
            'apk' => true,
            'tasks' => [['task' => 'Oil change', 'price' => 90]],
        ]);
        $this->tip($vehicle, 'winter_tires', VehicleTip::KEPT);

        $facts = app(VehicleAdviceFacts::class)->for($vehicle->fresh());

        $this->assertSame('Golf', $facts['vehicle']['model']);
        $this->assertSame(12, $facts['vehicle']['age_years']);
        $this->assertSame(182000, $facts['vehicle']['mileage_km']);
        // The RDW calls many a hatchback a stationwagen: the body type of the vehicle tells it.
        $this->assertArrayNotHasKey('body', $facts['rdw']);
        $this->assertSame(['big_maintenance'], $facts['maintenance_history'][0]['types']);
        $this->assertSame(['Oil change'], $facts['maintenance_history'][0]['tasks']);
        // The MOT expires within two months: the dashboard says so already.
        $this->assertContains('apk', array_column($facts['already_shown_notifications'], 'key'));
        $this->assertSame([['key' => 'winter_tires', 'subject' => 'tires', 'status' => 'kept', 'title' => 'Winter tires', 'since' => '2026-10-17']], $facts['handled_suggestions']);
    }

    public function test_the_fingerprint_does_not_change_with_the_day()
    {
        $vehicle = $this->signInWithVehicle()->fresh();
        $facts = app(VehicleAdviceFacts::class);

        $today = $facts->fingerprint($facts->for($vehicle));
        $this->travel(1)->day();

        $this->assertSame($today, $facts->fingerprint($facts->for($vehicle->fresh())));

        $vehicle->update(['mileage_latest' => $vehicle->mileage_latest + 10000]);

        $this->assertNotSame($today, $facts->fingerprint($facts->for($vehicle->fresh())));
    }

    public function test_a_refueling_does_not_change_the_fingerprint_unless_much_changed()
    {
        $vehicle = $this->signInWithVehicle(['mileage_latest' => 60000, 'fuel_types' => ['Unleaded 95 (E10)']]);
        $refuel = fn (string $date, int $from, float $consumption, int $km = 1800) => Refueling::factory()->for($vehicle)->create([
            'date' => $date,
            'fuel_type' => 'Unleaded 95 (E10)',
            'mileage_begin' => $from,
            'mileage_end' => $from + $km,
            'fuel_consumption' => $consumption,
            'driving_style' => 'average',
            'routes' => ['motorway'],
            'tires' => 'summer',
        ]);

        // A year of driving about 2000 km a month, the latest a few days ago.
        foreach (range(12, 1) as $month) {
            $refuel(today()->subMonths($month)->addDays(25)->toDateString(), 60000 - 1800 * $month, 5.4);
        }

        $facts = app(VehicleAdviceFacts::class);
        $before = $facts->for($vehicle->fresh());

        // The next one: a new date, some more kilometers, about the same consumption.
        $refuel('2026-10-16', 60000, 5.35, 900);
        $vehicle->update(['mileage_latest' => 60900]);
        $after = $facts->for($vehicle->fresh());

        // The notifications and the plan of the random vehicle of the factory may change with a refueling (a
        // component crossing a threshold by the kilometers): those count.
        $after['already_shown_notifications'] = $before['already_shown_notifications'];
        $after['maintenance_plan'] = $before['maintenance_plan'];
        $this->assertSame($facts->fingerprint($before), $facts->fingerprint($after));
    }

    public function test_the_facts_name_the_brand_rather_than_its_number()
    {
        $brands = config('vehicles.brands');
        $vehicle = $this->signInWithVehicle(['brand' => array_search('Seat', $brands, true)]);

        $this->assertSame('Seat', app(VehicleAdviceFacts::class)->for($vehicle->fresh())['vehicle']['brand']);
    }

    public function test_the_end_of_a_long_description_of_a_recent_maintenance_is_kept()
    {
        $vehicle = $this->signInWithVehicle();
        Maintenance::factory()->for($vehicle)->create([
            'date' => '2026-09-22',
            'description' => '<p>Diagnose: '.str_repeat('rammelend geluid bij sturen, ', 40).'</p><p>Conclusie: wielkuip gescheurd.</p>',
        ]);

        $description = app(VehicleAdviceFacts::class)->for($vehicle->fresh())['maintenance_history'][0]['description'];

        $this->assertStringStartsWith('Diagnose:', $description);
        $this->assertStringEndsWith('Conclusie: wielkuip gescheurd.', $description);
        $this->assertLessThanOrEqual(800, mb_strlen($description));
    }

    public function test_the_facts_tell_how_old_the_mileage_is()
    {
        $vehicle = $this->signInWithVehicle(['mileage_latest' => 11000]);
        Refueling::factory()->for($vehicle)->create(['date' => '2026-06-01', 'mileage_begin' => 10000, 'mileage_end' => 10500]);
        Refueling::factory()->for($vehicle)->create(['date' => '2026-08-01', 'mileage_begin' => 10500, 'mileage_end' => 11000]);

        $facts = app(VehicleAdviceFacts::class)->for($vehicle->fresh());

        // 250 km a month, for 77 days since: rounded to 500 km.
        $this->assertSame(['latest_recorded_on' => '2026-08-01', 'estimated_today_km' => 11500], $facts['mileage']);
    }

    public function test_the_facts_tell_the_trips_planned_in_the_coming_weeks()
    {
        $vehicle = $this->signInWithVehicle();
        $trip = fn (string $at, string $status) => Trip::create([
            'user_id' => $vehicle->user_id,
            'vehicle_id' => $vehicle->id,
            'time_at' => $at,
            'status' => $status,
            'plan' => ['routes' => [['distance' => 1100000, 'countries' => ['DE', 'AT'], 'vignettes' => ['AT']]]],
        ])->waypoints()->createMany([
            ['position' => 0, 'label' => 'Utrecht', 'latitude' => 52.0, 'longitude' => 5.0],
            ['position' => 1, 'label' => 'Innsbruck, Tirol, Austria', 'latitude' => 47.3, 'longitude' => 11.4],
        ]);
        $trip('2026-11-20 07:00:00', Trip::STATUS_PLANNED);
        $trip('2027-02-01 07:00:00', Trip::STATUS_PLANNED);
        $trip('2026-11-01 07:00:00', Trip::STATUS_FAILED);

        $facts = app(VehicleAdviceFacts::class)->for($vehicle->fresh());

        $this->assertCount(1, $facts['planned_trips']);
        $this->assertSame('2026-11-20', $facts['planned_trips'][0]['departure_date']);
        $this->assertSame(['Innsbruck'], $facts['planned_trips'][0]['destinations']);
        $this->assertSame(1100, $facts['planned_trips'][0]['distance_km']);
        $this->assertContains('Austria', $facts['planned_trips'][0]['vignette_countries']);
    }

    public function test_the_facts_tell_the_weather_but_only_frost_snow_or_heat_changes_the_fingerprint()
    {
        $minimum = 4;
        Http::swap(new Factory);
        Http::fake(['api.open-meteo.com/*' => function () use (&$minimum) {
            return Http::response(['daily' => [
                'time' => ['2026-10-17', '2026-10-18', '2026-10-19'],
                'temperature_2m_min' => [6, $minimum, $minimum - 1],
                'temperature_2m_max' => [14, 12, 11],
                'snowfall_sum' => [0, 0, 0],
            ]]);
        }]);
        $vehicle = $this->signInWithVehicle();
        $vehicle->user->update(['latitude' => 52.09, 'longitude' => 5.12]);
        $facts = app(VehicleAdviceFacts::class);

        $before = $facts->for($vehicle->fresh());
        $this->assertSame(3, $before['weather_forecast']['lowest_temperature_c']);
        $this->assertNull($before['weather_forecast']['first_frost_date']);

        // Colder, but no frost yet.
        $minimum = 2;
        Cache::flush();
        $this->assertSame($facts->fingerprint($before), $facts->fingerprint($facts->for($vehicle->fresh())));

        $minimum = 0;
        Cache::flush();
        $after = $facts->for($vehicle->fresh());
        $this->assertSame('2026-10-18', $after['weather_forecast']['first_frost_date']);
        $this->assertSame(2, $after['weather_forecast']['frost_days']);
        $this->assertNotSame($facts->fingerprint($before), $facts->fingerprint($after));
    }

    public function test_the_tips_not_handled_yet_are_given_again_without_changing_the_fingerprint()
    {
        VehicleAdvisor::fake([$this->answer([$this->suggestion(), $this->suggestion(['key' => 'battery', 'title' => 'Laat de accu testen'])])]);
        $vehicle = $this->signInWithVehicle();
        $facts = app(VehicleAdviceFacts::class);
        $before = $facts->fingerprint($facts->for($vehicle->fresh()));

        app(VehicleAdviceService::class)->generate($vehicle->fresh(), 'nl');

        $this->assertSame($before, $facts->fingerprint($facts->for($vehicle->fresh())));

        // A tip the owner handled is among those instead.
        $this->tip($vehicle, 'battery', VehicleTip::DONE);

        $this->assertSame([['key' => 'timing_belt', 'title' => 'Laat de distributieriem vervangen']], $facts->for($vehicle->fresh())['previous_suggestions']);
    }

    public function test_the_advice_is_generated_from_the_facts_in_the_language_of_the_user()
    {
        VehicleAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle(['brand' => 'Volkswagen']);

        $advice = app(VehicleAdviceService::class)->generate($vehicle, 'nl');

        $this->assertSame('timing_belt', $advice->suggestions[0]['key']);
        $this->assertSame('2027-03-01', $advice->suggestions[0]['due_date']);
        $this->assertSame(64, strlen($advice->facts_hash));
        VehicleAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"brand":"Volkswagen"')
            && str_contains($prompt->agent->instructions(), 'Dutch'));
    }

    public function test_what_the_model_makes_up_or_the_owner_handled_is_left_out()
    {
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['key' => 'Brake Fluid', 'priority' => 'urgent', 'basis' => 'guess', 'action' => 'call_garage', 'due_date' => '2026-01-01']),
            $this->suggestion(['key' => 'brake_fluid', 'title' => 'Twice']),
            $this->suggestion(['key' => 'flying', 'category' => 'aviation']),
            $this->suggestion(['key' => 'winter_tires']),
            $this->suggestion(['key' => 'untitled', 'title' => ' ']),
        ])]);

        $vehicle = $this->signInWithVehicle();
        $this->tip($vehicle, 'winter_tires', VehicleTip::NOT_RELEVANT);

        $suggestions = app(VehicleAdviceService::class)->generate($vehicle, 'en')->suggestions;

        $this->assertCount(1, $suggestions);
        $this->assertSame('brake_fluid', $suggestions[0]['key']);
        $this->assertSame('low', $suggestions[0]['priority']);
        $this->assertSame('general_knowledge', $suggestions[0]['basis']);
        $this->assertSame('none', $suggestions[0]['action']);
        // Never in the past.
        $this->assertSame('2026-10-17', $suggestions[0]['due_date']);
    }

    public function test_every_icon_the_model_can_choose_can_be_shown()
    {
        foreach (array_keys(VehicleAdvice::ICONS) as $icon) {
            [$set, $name] = explode(':', $icon);

            $this->assertFileExists(resource_path("icons/{$set}/{$name}.svg"), "[{$icon}] is not in resources/icons.");
        }
    }

    public function test_the_icon_the_model_chose_is_shown_or_else_the_one_of_the_category()
    {
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['key' => 'battery', 'icon' => 'mdi:car-battery']),
            $this->suggestion(['key' => 'brakes', 'icon' => 'mdi:made-up']),
        ])]);

        $vehicle = $this->signInWithVehicle();
        app(VehicleAdviceService::class)->generate($vehicle, 'en');
        $this->post(route('vehicle-advice.status'), ['key' => 'battery', 'status' => 'kept']);

        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tips.advice.suggestions.0.icon', 'mdi:car-wrench')
                // Kept with the icon it was given.
                ->where('tips.kept.0.icon', 'mdi:car-battery'));

        VehicleAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->agent->instructions(), 'mdi:car-battery: the battery'));
    }

    public function test_the_other_key_is_used_when_one_fails()
    {
        config(['ai.providers.gemini.key' => 'key-a, key-b']);
        $used = [];

        VehicleAdvisor::fake(function ($prompt, $attachments, $provider) use (&$used) {
            $used[] = $key = $provider->providerCredentials()['key'];

            if ($key === 'key-a') {
                throw RateLimitedException::forProvider('gemini');
            }

            return $this->answer();
        });

        $vehicle = $this->signInWithVehicle();

        app(VehicleAdviceService::class)->generate($vehicle, 'en');

        $this->assertSame('key-b', end($used));
    }

    public function test_the_fallback_model_is_asked_when_the_model_is_overloaded_with_every_key()
    {
        config(['ai.providers.gemini.key' => 'key-a, key-b', 'vehicle_advice.model' => 'flash', 'vehicle_advice.fallback_model' => 'lite']);
        $calls = 0;

        // Overloaded with both keys.
        VehicleAdvisor::fake(function () use (&$calls) {
            if (++$calls <= 2) {
                throw new RuntimeException('Overloaded');
            }

            return $this->answer();
        });

        $this->assertNotNull(app(VehicleAdviceService::class)->generate($this->signInWithVehicle(), 'en'));
        $this->assertSame(3, $calls);
        VehicleAdvisor::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->model() === 'lite');
    }

    public function test_the_dashboard_shows_the_tips_the_most_important_first()
    {
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['priority' => 'low', 'key' => 'washing', 'category' => 'season', 'action' => 'none']),
            $this->suggestion(),
        ])]);

        $vehicle = $this->signInWithVehicle();
        app(VehicleAdviceService::class)->generate($vehicle, 'en');

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('advice', fn (Assert $reload) => $reload
                    ->where('vehicleAdvice.advice.suggestions.0.key', 'timing_belt')
                    ->where('vehicleAdvice.advice.suggestions.0.icon', 'mdi:car-wrench')
                    ->where('vehicleAdvice.advice.suggestions.0.url', route('maintenances.create'))
                    ->where('vehicleAdvice.advice.suggestions.0.due', '1 March 2027')
                    ->where('vehicleAdvice.advice.suggestions.1.url', null)
                    ->where('vehicleAdvice.canGenerate', false)
                    ->where('vehicleAdvice.overviewUrl', route('tips'))
                    ->etc()));
    }

    public function test_a_tip_done_or_kept_leaves_the_dashboard_for_the_overview()
    {
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['key' => 'timing_belt']),
            $this->suggestion(['key' => 'winter_tires', 'title' => 'Winterbanden']),
            $this->suggestion(['key' => 'washing', 'title' => 'Wassen']),
            $this->suggestion(['key' => 'insurance_comparison', 'title' => 'Verzekering']),
        ])]);

        $vehicle = $this->signInWithVehicle();
        app(VehicleAdviceService::class)->generate($vehicle, 'en');

        foreach (['timing_belt' => 'done', 'winter_tires' => 'kept', 'washing' => 'not_relevant'] as $key => $status) {
            $this->post(route('vehicle-advice.status'), ['key' => $key, 'status' => $status])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('vehicle_tips', [
            'vehicle_id' => $vehicle->id,
            'key' => 'timing_belt',
            'status' => 'done',
            'title' => 'Laat de distributieriem vervangen',
            'body' => 'Bij deze motor gaat de riem meestal 120.000 km of 6 jaar mee.',
        ]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('advice', fn (Assert $reload) => $reload
                    ->has('vehicleAdvice.advice.suggestions', 1)
                    ->where('vehicleAdvice.advice.suggestions.0.key', 'insurance_comparison')
                    ->etc()));

        // Not relevant is gone altogether.
        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tips')
                ->has('tips.advice.suggestions', 1)
                ->has('tips.kept', 1)
                ->where('tips.kept.0.key', 'winter_tires')
                ->where('tips.kept.0.handledAt', '17 October 2026')
                ->has('tips.done', 1)
                ->where('tips.done.0.key', 'timing_belt'));
    }

    public function test_a_handled_tip_stays_in_the_overview_after_its_advice_is_pruned()
    {
        VehicleAdvisor::fake([$this->answer(), ['suggestions' => []]]);

        $vehicle = $this->signInWithVehicle();
        app(VehicleAdviceService::class)->generate($vehicle, 'en');
        $this->post(route('vehicle-advice.status'), ['key' => 'timing_belt', 'status' => 'kept']);

        $this->travel(4)->months();
        $this->artisan('model:prune', ['--model' => [VehicleAdvice::class]]);
        app(VehicleAdviceService::class)->generate($vehicle, 'en');

        $this->assertSame(1, $vehicle->vehicleAdvices()->count());
        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tips.advice.suggestions', 0)
                ->where('tips.kept.0.title', 'Laat de distributieriem vervangen')
                ->where('tips.kept.0.url', route('maintenances.create')));
    }

    public function test_a_tip_in_the_overview_can_be_done_after_all_or_removed()
    {
        VehicleAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle();
        app(VehicleAdviceService::class)->generate($vehicle, 'en');

        $this->post(route('vehicle-advice.status'), ['key' => 'timing_belt', 'status' => 'kept']);
        $this->post(route('vehicle-advice.status'), ['key' => 'timing_belt', 'status' => 'done']);
        $this->assertSame('done', VehicleTip::query()->sole()->status);

        $this->post(route('vehicle-advice.status'), ['key' => 'timing_belt', 'status' => 'not_relevant']);

        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page->has('tips.kept', 0)->has('tips.done', 0));
    }

    public function test_a_tip_needs_a_known_status_and_has_to_exist()
    {
        $this->signInWithVehicle();

        $this->post(route('vehicle-advice.status'), ['key' => 'timing_belt', 'status' => 'later'])
            ->assertSessionHasErrors('status');

        $this->post(route('vehicle-advice.status'), ['key' => 'timing_belt', 'status' => 'done'])
            ->assertSessionHasErrors('key');
    }

    public function test_an_important_tip_that_is_due_soon_needs_attention()
    {
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['key' => 'battery', 'priority' => 'high', 'due_date' => '2026-11-01', 'title' => 'Laat de accu testen']),
            $this->suggestion(['key' => 'timing_belt', 'priority' => 'high', 'due_date' => '2027-03-01']),
            $this->suggestion(['key' => 'insurance_comparison', 'priority' => 'medium', 'due_date' => '']),
        ])]);

        $vehicle = $this->signInWithVehicle();
        app(VehicleAdviceService::class)->generate($vehicle, 'en');

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('notifications', fn ($notifications) => collect($notifications)->pluck('key')->filter(fn ($key) => str_starts_with($key, 'vehicle_advice:'))->values()->all() === ['vehicle_advice:battery'])
                    ->where('notifications', fn ($notifications) => collect($notifications)->firstWhere('key', 'vehicle_advice:battery')['opensVehicleAdvice'] === true
                        && collect($notifications)->firstWhere('key', 'vehicle_advice:battery')['text'] === 'Laat de accu testen')
                    // "All is well" no longer is.
                    ->where('notifications', fn ($notifications) => ! collect($notifications)->contains('type', 'success'))
                    ->etc()));
    }

    public function test_the_daily_run_only_asks_again_when_the_facts_changed_or_after_a_week()
    {
        VehicleAdvisor::fake([$this->answer(), $this->answer(), $this->answer()]);

        $vehicle = Vehicle::factory()->for(User::factory()->create(['language' => 'de']))->create(['status' => 'drivable', 'powertrain' => 'electricity', 'rdw_data' => null]);
        $sold = Vehicle::factory()->create(['status' => 'sold']);
        $inactive = Vehicle::factory()->for(User::factory()->inactive()->create())->create(['status' => 'drivable']);

        $this->artisan('generate:vehicle-advices')->assertExitCode(0);
        $this->assertSame(['de'], $vehicle->vehicleAdvices()->pluck('locale')->all());
        $this->assertSame(0, $sold->vehicleAdvices()->count());
        $this->assertSame(0, $inactive->vehicleAdvices()->count());

        // Nothing changed.
        $this->travel(1)->day();
        $this->artisan('generate:vehicle-advices');
        $this->assertSame(1, $vehicle->vehicleAdvices()->count());

        // The mileage did, by more than driving on.
        $vehicle->update(['mileage_latest' => $vehicle->mileage_latest + 10000]);
        $this->artisan('generate:vehicle-advices');
        $this->assertSame(2, $vehicle->vehicleAdvices()->count());

        // A week later, whatever the facts.
        $this->travel(8)->days();
        $this->artisan('generate:vehicle-advices');
        $this->assertSame(3, $vehicle->vehicleAdvices()->count());
        VehicleAdvisor::assertPromptedTimes(3);
    }

    public function test_the_tips_can_be_generated_by_hand_whenever_the_owner_last_logged_in()
    {
        VehicleAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle();
        $vehicle->user->forceFill(['last_login_at' => now()->subYear()])->save();

        $this->post(route('vehicle-advice.store'));

        $this->assertSame(1, $vehicle->vehicleAdvices()->count());
    }

    public function test_the_tips_are_not_generated_by_hand_again_within_minutes()
    {
        VehicleAdvisor::fake([$this->answer(), $this->answer()]);

        $vehicle = $this->signInWithVehicle(['status' => 'suspended']);

        $this->from(route('dashboard'))->post(route('vehicle-advice.store'))->assertRedirect(route('dashboard'));
        $this->post(route('vehicle-advice.store'));
        $this->assertSame(1, $vehicle->vehicleAdvices()->count());

        $this->travel(11)->minutes();
        $this->post(route('vehicle-advice.store'));
        $this->assertSame(2, $vehicle->vehicleAdvices()->count());
    }

    public function test_a_failing_model_is_reported_to_the_user()
    {
        VehicleAdvisor::fake(fn () => throw new RuntimeException('Down'));

        $vehicle = $this->signInWithVehicle();

        $this->post(route('vehicle-advice.store'))->assertSessionHasNoErrors();

        $this->assertSame(0, $vehicle->vehicleAdvices()->count());
    }

    public function test_the_daily_run_is_scheduled_after_the_import_of_the_vehicle_data()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'generate:vehicle-advices'));

        $this->assertSame('30 2 * * *', $event?->expression);
        $this->assertTrue($event?->withoutOverlapping);
        $this->assertTrue($event?->onOneServer);
    }

    public function test_the_advice_is_not_generated_twice_at_the_same_time()
    {
        VehicleAdvisor::fake([$this->answer()]);
        $vehicle = $this->signInWithVehicle();

        // Another run is generating it right now.
        $lock = Cache::lock("vehicle-advice:{$vehicle->id}:nl", 300);
        $lock->get();

        $this->assertNull(app(VehicleAdviceService::class)->refresh($vehicle->fresh(), 'nl'));
        $this->assertNull(app(VehicleAdviceService::class)->generate($vehicle->fresh(), 'nl'));
        VehicleAdvisor::assertNeverPrompted();

        $lock->release();
        $this->assertNotNull(app(VehicleAdviceService::class)->refresh($vehicle->fresh(), 'nl'));
        // Right after: up to date.
        $this->assertNull(app(VehicleAdviceService::class)->refresh($vehicle->fresh(), 'nl'));
    }

    public function test_a_tip_that_only_plans_a_garage_visit_is_left_out()
    {
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['key' => 'garage_visit_combination', 'subject' => 'garage_visit', 'title' => 'Combineer reparaties']),
            $this->suggestion(),
        ])]);
        $vehicle = $this->signInWithVehicle();

        $this->assertSame(['timing_belt'], array_column(app(VehicleAdviceService::class)->generate($vehicle, 'nl')->suggestions, 'key'));
    }

    public function test_a_finding_on_the_garage_list_gets_a_tip_only_once_whatever_its_key()
    {
        $vehicle = $this->signInWithVehicle();
        $finding = Finding::factory()->for($vehicle)->create(['title' => 'Rattle when accelerating', 'for_garage' => true]);
        $other = Finding::factory()->for($vehicle)->create(['title' => 'Squeaking door', 'for_garage' => false]);
        $about = fn (Finding $finding, string $key) => $this->suggestion(['key' => $key, 'subject' => 'open_finding', 'finding_id' => $finding->id, 'title' => $key]);

        VehicleAdvisor::fake([
            $this->answer([$about($finding, 'rattle_questions'), $about($finding, 'rattle_twice')]),
            $this->answer([$about($finding, 'act_mode_rattle'), $about($other, 'door_hinges'), $this->suggestion(['key' => 'made_up', 'finding_id' => 'not-a-finding'])]),
        ]);
        $advices = app(VehicleAdviceService::class);

        // Once per finding, with the finding it is about.
        $first = $advices->generate($vehicle->fresh(), 'en');
        $this->assertSame(['rattle_questions'], array_column($first->suggestions, 'key'));
        $this->assertSame($finding->id, $first->suggestions[0]['finding_id']);

        $this->post(route('vehicle-advice.status'), ['key' => 'rattle_questions', 'status' => 'not_relevant']);
        $this->assertSame($finding->id, VehicleTip::query()->sole()->finding_id);

        // Then not again, under a new key; a finding not on the list still can; a made-up finding is none.
        $this->travel(11)->minutes();
        $second = $advices->generate($vehicle->fresh(), 'en');
        $this->assertSame(['door_hinges', 'made_up'], array_column($second->suggestions, 'key'));
        $this->assertNull($second->suggestions[1]['finding_id']);
        $this->assertNotContains($finding->id, array_column($second->facts['open_findings'], 'id'));
    }

    public function test_a_tip_done_records_what_was_done_with_the_components_without_a_maintenance()
    {
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['key' => 'battery_test', 'subject' => 'battery', 'title' => 'Laat de accu testen']),
            $this->suggestion(['key' => 'brake_wear', 'subject' => 'brakes', 'title' => 'Remmen']),
            $this->suggestion(['key' => 'insurance_comparison', 'subject' => 'insurance', 'title' => 'Verzekering']),
        ])]);
        $vehicle = $this->signInWithVehicle(['mileage_latest' => 104000]);
        app(VehicleAdviceService::class)->generate($vehicle, 'en');

        // What can be recorded per tip, with the defaults.
        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tips.today', '2026-10-17')
                ->where('tips.mileage', 104000)
                ->where('tips.advice.suggestions', fn ($tips) => collect($tips)->mapWithKeys(fn ($tip) => [$tip['key'] => array_column($tip['components'], 'key')])->all() === [
                    'battery_test' => ['battery_12v'],
                    'brake_wear' => ['front_brake_pads', 'rear_brake_pads', 'front_brake_discs', 'rear_brake_discs'],
                    'insurance_comparison' => [],
                ]));

        // Checked: the interval does not start again.
        $this->post(route('vehicle-advice.status'), ['key' => 'battery_test', 'status' => 'done', 'record' => [
            'kind' => 'checked', 'components' => ['battery_12v'], 'date' => '2026-10-15', 'km' => 103900,
        ]])->assertSessionHasNoErrors();
        // Replaced: it does; a component the tip is not about is left alone.
        $this->post(route('vehicle-advice.status'), ['key' => 'brake_wear', 'status' => 'done', 'record' => [
            'kind' => 'replaced', 'components' => ['front_brake_pads', 'engine_oil'], 'date' => '2026-10-16', 'km' => null,
        ]])->assertSessionHasNoErrors();

        $components = $vehicle->components()->get()->keyBy('key');
        $this->assertSame('2026-10-15', $components['battery_12v']->checked_date->toDateString());
        $this->assertSame(103900, $components['battery_12v']->checked_km);
        $this->assertNull($components['battery_12v']->done_date);
        $this->assertSame('2026-10-16', $components['front_brake_pads']->done_date->toDateString());
        $this->assertFalse($components->has('engine_oil'));
        $this->assertSame(0, $vehicle->maintenances()->count());

        // The model hears of the check.
        $battery = collect(app(VehicleAdviceFacts::class)->for($vehicle->fresh())['maintenance_plan'])->firstWhere('component', 'battery_12v');
        $this->assertSame('2026-10-15', $battery['last_checked']);

        // Never in the future.
        $this->post(route('vehicle-advice.status'), ['key' => 'insurance_comparison', 'status' => 'done', 'record' => [
            'kind' => 'checked', 'components' => ['battery_12v'], 'date' => '2026-12-01',
        ]])->assertSessionHasErrors('record.date');
    }

    public function test_with_a_trip_planned_its_rules_come_from_the_trip_planner()
    {
        $vehicle = $this->signInWithVehicle();
        Trip::create([
            'user_id' => $vehicle->user_id,
            'vehicle_id' => $vehicle->id,
            'time_at' => '2026-11-20 08:00:00',
            'status' => Trip::STATUS_PLANNED,
            'plan' => ['routes' => [['distance' => 218000, 'countries' => ['NL', 'BE'], 'notices' => [
                ['level' => 'info', 'key' => 'winter_tires', 'params' => ['countries' => 'DE']],
            ]]]],
        ]);
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['key' => 'belgium_vignette', 'subject' => 'vignette', 'category' => 'legal', 'title' => 'Milieuzone']),
            $this->suggestion(),
        ])]);

        $advice = app(VehicleAdviceService::class)->generate($vehicle->fresh(), 'nl');

        $this->assertSame(['Winter tires can be required in Germany at this time of year.'], $advice->facts['planned_trips'][0]['notices_of_the_trip_planner']);
        $this->assertSame(['timing_belt'], array_column($advice->suggestions, 'key'));
    }

    public function test_a_handled_tip_can_come_back_after_a_while()
    {
        $vehicle = $this->signInWithVehicle();
        $this->tip($vehicle, 'battery_health', VehicleTip::DONE);
        VehicleAdvisor::fake([
            $this->answer([$this->suggestion(['key' => 'battery_winter', 'subject' => 'battery', 'title' => 'Accu'])]),
            $this->answer([$this->suggestion(['key' => 'battery_winter', 'subject' => 'battery', 'title' => 'Accu'])]),
        ]);
        $advices = app(VehicleAdviceService::class);

        $this->assertSame([], $advices->generate($vehicle->fresh(), 'en')->suggestions);

        // Next winter: done half a year ago, so the battery can be a tip again.
        $this->travel(181)->days();
        $this->assertSame(['battery_winter'], array_column($advices->generate($vehicle->fresh(), 'en')->suggestions, 'key'));
        $this->assertSame([], $advices->generate($vehicle->fresh(), 'en')->facts['handled_suggestions'] ?? []);
    }

    public function test_the_tips_are_deduplicated_by_subject_even_with_different_keys()
    {
        $vehicle = $this->signInWithVehicle();
        // User handled a battery tip under key battery_health
        $this->tip($vehicle, 'battery_health', VehicleTip::DONE);

        // Model returns a new tip about battery under a different key battery_age_prevention
        VehicleAdvisor::fake([$this->answer([
            $this->suggestion(['key' => 'battery_age_prevention', 'subject' => 'battery', 'title' => 'Staat van de 12V accu']),
            $this->suggestion(['key' => 'wiper_blades', 'subject' => 'wiper_blades', 'title' => 'Vervang ruitenwissers']),
        ])]);

        $advice = app(VehicleAdviceService::class)->generate($vehicle->fresh(), 'nl');

        // The battery suggestion is rejected because subject 'battery' was already handled
        $keys = array_column($advice->suggestions, 'key');
        $this->assertNotContains('battery_age_prevention', $keys);
        $this->assertContains('wiper_blades', $keys);
    }

    public function test_open_findings_on_garage_list_with_handled_tips_are_suppressed_from_facts()
    {
        $vehicle = $this->signInWithVehicle();
        $finding = Finding::factory()->for($vehicle)->create([
            'title' => 'Wielkuip rechtsvoor vervangen',
            'for_garage' => true,
        ]);

        $facts = app(VehicleAdviceFacts::class)->for($vehicle->fresh());
        $this->assertContains('Wielkuip rechtsvoor vervangen', array_column($facts['open_findings'], 'title'));

        // Once the owner handles a tip regarding this finding, it should be suppressed
        $this->tip($vehicle, 'wheel_well_replacement', VehicleTip::KEPT);

        $freshFacts = app(VehicleAdviceFacts::class)->for($vehicle->fresh());
        $this->assertNotContains('Wielkuip rechtsvoor vervangen', array_column($freshFacts['open_findings'], 'title'));
    }

    public function test_handling_a_tip_does_not_change_the_facts_fingerprint()
    {
        $vehicle = $this->signInWithVehicle();
        $facts = app(VehicleAdviceFacts::class);
        $before = $facts->fingerprint($facts->for($vehicle->fresh()));

        $this->tip($vehicle, 'wiper_blades', VehicleTip::DONE);

        $after = $facts->fingerprint($facts->for($vehicle->fresh()));
        $this->assertSame($before, $after);
    }
}
