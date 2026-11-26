<?php

namespace Tests\Feature;

use App\Ai\Agents\VehicleAdvisor;
use App\Models\Finding;
use App\Models\Fine;
use App\Models\GarageListItem;
use App\Models\Parking;
use App\Models\Product;
use App\Models\Service;
use App\Models\Tax;
use App\Models\Toll;
use App\Models\Vehicle;
use App\Models\VehicleTip;
use App\Services\VehicleAdviceFacts;
use App\Services\VehicleAdviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

/**
 * How long a handled tip keeps the model from making it again, how it comes
 * back for a change since, how it is shown then, and the facts of the
 * records the tips are based on besides.
 */
class VehicleTipReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-17 12:00:00');
        Sleep::fake();
        config(['ai.providers.gemini.key' => 'test-key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestion(array $overrides = []): array
    {
        return [
            'key' => 'brake_wear',
            'subject' => 'brakes',
            'category' => 'maintenance',
            'priority' => 'medium',
            'basis' => 'general_knowledge',
            'action' => 'add_maintenance',
            'due_date' => '',
            'title' => 'Laat de remmen nakijken',
            'body' => 'Bij deze leeftijd slijten de remschijven vaak.',
            'returns_because' => '',
            'news_id' => '',
            ...$overrides,
        ];
    }

    private function tip(Vehicle $vehicle, string $status, array $overrides = []): VehicleTip
    {
        return VehicleTip::create([
            ...$this->suggestion(['due_date' => null]),
            'vehicle_id' => $vehicle->id,
            'status' => $status,
            'locale' => 'en',
            ...$overrides,
        ]);
    }

    /**
     * @return list<string>
     */
    private function keys(Vehicle $vehicle, array $suggestions): array
    {
        VehicleAdvisor::fake([['suggestions' => $suggestions]]);

        return array_column(app(VehicleAdviceService::class)->generate($vehicle->fresh(), 'en')->suggestions, 'key');
    }

    public function test_a_tip_done_counts_four_weeks_one_not_relevant_half_a_year_one_kept_as_long_as_it_is_kept()
    {
        $vehicle = $this->signInWithVehicle();
        $this->tip($vehicle, VehicleTip::DONE, ['key' => 'battery_test', 'subject' => 'battery']);
        $this->tip($vehicle, VehicleTip::NOT_RELEVANT, ['key' => 'washing', 'subject' => 'other']);
        $this->tip($vehicle, VehicleTip::KEPT, ['key' => 'timing_belt', 'subject' => 'timing_belt']);
        $all = [
            $this->suggestion(['key' => 'battery_test', 'subject' => 'battery']),
            $this->suggestion(['key' => 'washing', 'subject' => 'other']),
            $this->suggestion(['key' => 'timing_belt', 'subject' => 'timing_belt']),
        ];

        $this->assertSame([], $this->keys($vehicle, $all));

        $this->travel(29)->days();
        $this->assertSame(['battery_test'], $this->keys($vehicle, $all));

        $this->travel(152)->days();
        $this->assertSame(['battery_test', 'washing'], $this->keys($vehicle, $all));

        // Kept: still in the overview after a year, so still in force.
        $this->travel(1)->year();
        $this->assertNotContains('timing_belt', $this->keys($vehicle, $all));
    }

    public function test_a_handled_tip_comes_back_for_a_change_since_and_shows_on_the_dashboard_and_only_among_the_current_tips()
    {
        $vehicle = $this->signInWithVehicle();
        $before = Finding::factory()->for($vehicle)->create(['title' => 'Squeaking door']);
        $this->tip($vehicle, VehicleTip::DONE);
        $this->travel(3)->days();
        $finding = Finding::factory()->for($vehicle)->create(['title' => 'Grinding noise when braking', 'components' => ['front_brake_discs']]);

        $facts = app(VehicleAdviceFacts::class)->for($vehicle->fresh());
        $this->assertSame(["finding:{$finding->id}"], $facts['handled_suggestions'][0]['changes_since']);
        $this->assertStringContainsString('Grinding noise when braking', $facts['changes_since_handled'][0]['what']);
        $this->assertNotContains("finding:{$before->id}", array_column($facts['changes_since_handled'], 'id'));

        // Without a change, or with one that is not listed: not back.
        $this->assertSame([], $this->keys($vehicle, [$this->suggestion()]));
        $this->travel(11)->minutes();
        $this->assertSame([], $this->keys($vehicle, [$this->suggestion(['key' => 'brake_discs', 'returns_because' => 'finding:made-up'])]));

        // For the new finding: back, also under another key about the same subject.
        $this->travel(11)->minutes();
        $this->assertSame(['brake_wear'], $this->keys($vehicle, [$this->suggestion(['title' => 'Remschijven slijten', 'returns_because' => "finding:{$finding->id}"])]));
        VehicleAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->agent->instructions(), 'returns_because'));

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('advice', fn (Assert $reload) => $reload
                    ->where('vehicleAdvice.advice.suggestions.0.key', 'brake_wear')
                    ->where('vehicleAdvice.advice.suggestions.0.badge', 'updated')
                    ->where('vehicleAdvice.advice.suggestions.0.lastHandled', ['status' => 'done', 'at' => '17 October 2026'])
                    ->etc()));

        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tips.advice.suggestions', 1)
                ->has('tips.done', 0));

        // Done again: gone, as the change is from before.
        $this->post(route('vehicle-advice.status'), ['key' => 'brake_wear', 'status' => 'done'])->assertSessionHasNoErrors();
        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tips.advice.suggestions', 0)
                ->where('tips.done.0.title', 'Remschijven slijten'));
    }

    public function test_the_weather_is_no_change_for_a_tip_that_is_not_relevant()
    {
        $vehicle = $this->signInWithVehicle();
        $done = $this->tip($vehicle, VehicleTip::DONE, ['key' => 'battery_test', 'subject' => 'battery']);
        $notRelevant = $this->tip($vehicle, VehicleTip::NOT_RELEVANT, ['key' => 'winter_tires', 'subject' => 'tires']);
        $this->travel(10)->days();

        $handled = collect(app(VehicleAdviceFacts::class)->handled($vehicle->fresh(), [
            'weather_forecast' => ['first_frost_date' => '2026-11-05'],
        ])['handled_suggestions'])->keyBy('key');

        $this->assertSame(['weather:frost:2026-11-05'], $handled[$done->key]['changes_since']);
        $this->assertSame([], $handled[$notRelevant->key]['changes_since']);
    }

    public function test_what_a_tip_done_recorded_is_no_change_to_come_back_for()
    {
        VehicleAdvisor::fake([['suggestions' => [$this->suggestion(['key' => 'battery_test', 'subject' => 'battery'])]]]);
        $vehicle = $this->signInWithVehicle();
        app(VehicleAdviceService::class)->generate($vehicle, 'en');

        $this->post(route('vehicle-advice.status'), ['key' => 'battery_test', 'status' => 'done', 'record' => [
            'kind' => 'checked', 'components' => ['battery_12v'], 'date' => '2026-10-17', 'km' => null,
        ]])->assertSessionHasNoErrors();
        $this->travel(1)->day();

        $this->assertSame([], app(VehicleAdviceFacts::class)->for($vehicle->fresh())['handled_suggestions'][0]['changes_since']);
    }

    public function test_a_tip_that_is_new_since_the_advice_before_is_marked_as_such()
    {
        $vehicle = $this->signInWithVehicle();
        VehicleAdvisor::fake([
            ['suggestions' => [$this->suggestion()]],
            ['suggestions' => [$this->suggestion(), $this->suggestion(['key' => 'battery_test', 'subject' => 'battery', 'title' => 'Accu'])]],
        ]);
        $advices = app(VehicleAdviceService::class);
        $advices->generate($vehicle, 'en');
        $this->travel(11)->minutes();
        $advices->generate($vehicle->fresh(), 'en');

        $badges = collect($advices->forDashboard($vehicle->fresh())['advice']['suggestions'])->pluck('badge', 'key')->all();

        $this->assertSame(['brake_wear' => null, 'battery_test' => 'new'], $badges);
    }

    public function test_the_facts_tell_the_records_the_tips_can_be_about_besides()
    {
        $vehicle = $this->signInWithVehicle();
        Service::factory()->for($vehicle)->create(['type' => 'roadside_assistance', 'date' => '2026-09-01', 'name' => 'Battery dead']);
        Service::factory()->for($vehicle)->create(['type' => 'coffee', 'date' => '2026-09-02']);
        Product::factory()->for($vehicle)->create(['category' => 'part', 'name' => 'Wiper blades', 'date' => '2026-10-01']);
        GarageListItem::create(['vehicle_id' => $vehicle->id, 'text' => 'Ask about the rattle']);
        Fine::factory()->for($vehicle)->create(['date' => '2026-08-01', 'country' => 'BE', 'price' => 150]);
        Tax::factory()->for($vehicle)->create(['start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'price' => 210]);
        foreach (['2026-05-01', '2026-06-01'] as $date) {
            Toll::factory()->for($vehicle)->create(['start_date' => $date, 'country' => 'IT', 'road' => ['A1'], 'start_location' => 'Milano', 'end_location' => 'Bologna', 'toll_company' => 'Autostrade', 'price' => 12]);
        }
        foreach (['2026-05-01', '2026-06-01', '2026-07-01'] as $date) {
            Parking::factory()->for($vehicle)->create(['start_time' => "{$date} 09:00:00", 'location' => 'Station Utrecht', 'company' => 'Q-Park', 'price' => 8]);
        }

        $facts = app(VehicleAdviceFacts::class)->for($vehicle->fresh());

        $this->assertSame(['roadside_assistance'], array_column($facts['services_past_year'], 'type'));
        $this->assertSame('Wiper blades', $facts['products_past_year'][0]['name']);
        $this->assertSame(['Ask about the rattle'], $facts['garage_list']);
        $this->assertSame(150.0, $facts['fines_past_year'][0]['price_eur']);
        $this->assertSame(['paid_until' => '2026-12-31', 'price_eur' => 210.0, 'start_date' => '2026-07-01'], $facts['road_tax']);
        $this->assertSame(['where' => 'IT A1 Milano – Bologna Autostrade', 'times' => 2, 'total_eur' => 24.0], array_intersect_key($facts['recurring_tolls'][0], array_flip(['where', 'times', 'total_eur'])));
        $this->assertSame(3, $facts['recurring_parking'][0]['times']);

        // Parking there once more changes what it cost, not the facts the advice is made again for.
        $fingerprint = app(VehicleAdviceFacts::class)->fingerprint($facts);
        Parking::factory()->for($vehicle)->create(['start_time' => '2026-08-01 09:00:00', 'location' => 'Station Utrecht', 'company' => 'Q-Park', 'price' => 8]);
        $after = app(VehicleAdviceFacts::class)->for($vehicle->fresh());
        $after['already_shown_notifications'] = $facts['already_shown_notifications'];
        $this->assertSame($fingerprint, app(VehicleAdviceFacts::class)->fingerprint($after));
    }
}
