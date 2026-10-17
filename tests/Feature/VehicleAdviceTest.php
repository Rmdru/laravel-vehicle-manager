<?php

namespace Tests\Feature;

use App\Ai\Agents\VehicleAdvisor;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAdvice;
use App\Models\VehicleTip;
use App\Services\VehicleAdviceFacts;
use App\Services\VehicleAdviceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame('hatchback', $facts['rdw']['body']);
        $this->assertSame('big_maintenance', $facts['maintenance_history'][0]['type']);
        $this->assertSame(['Oil change'], $facts['maintenance_history'][0]['tasks']);
        // The MOT expires within two months: the dashboard says so already.
        $this->assertContains('apk', array_column($facts['already_shown_notifications'], 'key'));
        $this->assertSame([['key' => 'winter_tires', 'status' => 'kept', 'title' => 'Winter tires', 'since' => '2026-10-17']], $facts['handled_suggestions']);
    }

    public function test_the_fingerprint_does_not_change_with_the_day()
    {
        $vehicle = $this->signInWithVehicle()->fresh();
        $facts = app(VehicleAdviceFacts::class);

        $today = $facts->fingerprint($facts->for($vehicle));
        $this->travel(1)->day();

        $this->assertSame($today, $facts->fingerprint($facts->for($vehicle->fresh())));

        $vehicle->update(['mileage_latest' => 5000]);

        $this->assertNotSame($today, $facts->fingerprint($facts->for($vehicle->fresh())));
    }

    public function test_the_advice_is_generated_from_the_facts_in_the_language_of_the_user()
    {
        VehicleAdvisor::fake([$this->answer()]);

        $vehicle = $this->signInWithVehicle(['brand' => 'Volkswagen']);

        $advice = app(VehicleAdviceService::class)->generate($vehicle, 'nl');

        $this->assertSame('timing_belt', $advice->suggestions[0]['key']);
        $this->assertSame('2027-03-01', $advice->suggestions[0]['due_date']);
        $this->assertSame(64, strlen($advice->facts_hash));
        VehicleAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"brand": "Volkswagen"')
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

        $this->artisan('generate:vehicle-advices')->assertExitCode(0);
        $this->assertSame(['de'], $vehicle->vehicleAdvices()->pluck('locale')->all());
        $this->assertSame(0, $sold->vehicleAdvices()->count());

        // Nothing changed.
        $this->travel(1)->day();
        $this->artisan('generate:vehicle-advices');
        $this->assertSame(1, $vehicle->vehicleAdvices()->count());

        // The mileage did.
        $vehicle->update(['mileage_latest' => $vehicle->mileage_latest + 1000]);
        $this->artisan('generate:vehicle-advices');
        $this->assertSame(2, $vehicle->vehicleAdvices()->count());

        // A week later, whatever the facts.
        $this->travel(8)->days();
        $this->artisan('generate:vehicle-advices');
        $this->assertSame(3, $vehicle->vehicleAdvices()->count());
        VehicleAdvisor::assertPromptedTimes(3);
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
    }
}
