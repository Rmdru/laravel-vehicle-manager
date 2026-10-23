<?php

namespace Tests\Feature;

use App\Ai\Agents\MonthlySummarizer;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Models\VehicleSummary;
use App\Services\MonthlySummaryFacts;
use App\Services\MonthlySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class MonthlySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 12:00:00');
        // A single key, whatever .env holds.
        config(['ai.providers.gemini.key' => 'test-key']);
    }

    /**
     * @return array{headline: string, text: string}
     */
    private function answer(): array
    {
        return ['headline' => 'Een rustige maand', 'text' => 'Je reed ongeveer zoals gewoonlijk, en het onderhoud maakte de maand duurder.'];
    }

    /**
     * A vehicle bought a year ago at 10.000 km, driven 1.000 km a month, with
     * a maintenance this month and the MOT and the insurance due soon.
     */
    private function vehicle(): Vehicle
    {
        $vehicle = $this->signInWithVehicle([
            'purchase_date' => '2025-09-30',
            'mileage_start' => 10000,
            'maintenance_interval_distance' => 0,
            'maintenance_interval_time' => 12,
            'rdw_data' => ['vervaldatum_apk_dt' => '2026-11-20T00:00:00.000'],
        ]);

        $refuel = fn (string $date, int $begin, int $end, float $price) => Refueling::factory()->for($vehicle)->create([
            'date' => $date,
            'mileage_begin' => $begin,
            'mileage_end' => $end,
            'total_price' => $price,
            'fuel_consumption' => 6.5,
            'charge_time' => null,
        ]);

        $refuel('2026-09-30', 21400, 22000, 80);
        $refuel('2026-10-15', 22000, 22600, 60);

        Maintenance::factory()->for($vehicle)->create([
            'date' => '2026-10-10',
            'mileage' => 22400,
            'type_maintenance' => 'big_maintenance',
            'apk' => false,
            'total_price' => 250,
        ]);

        Insurance::factory()->for($vehicle)->create([
            'start_date' => '2025-12-01',
            'end_date' => '2026-12-01',
            'price' => 50,
            'invoice_day' => 5,
        ]);

        return $vehicle->fresh();
    }

    public function test_the_facts_are_of_the_month_so_far()
    {
        $facts = app(MonthlySummaryFacts::class)->for($this->vehicle());

        $this->assertSame(['kind' => 'month_to_date', 'month' => '2026-10', 'start' => '2026-10-01', 'end' => '2026-10-23', 'days' => 23, 'days_in_month' => 31], $facts['period']);

        // After the last refueling, at the pace of the year before: an estimate.
        $this->assertSame(865, $facts['distance']['km']);
        $this->assertTrue($facts['distance']['estimated']);
        $this->assertSame(1001, $facts['distance']['average_month_km']);
        $this->assertEqualsWithDelta(1001 * 23 / 31, $facts['distance']['expected_km'], 1);

        $this->assertEquals(360, $facts['costs']['total_eur']);
        $this->assertEquals(60, $facts['costs']['running_eur']);
        $this->assertEquals(50, $facts['costs']['fixed_eur']);
        $this->assertEquals(['Maintenance' => 250], $facts['costs']['incidental_eur']);

        $this->assertEquals(6.5, $facts['consumption']['value']);
        $this->assertSame(1, $facts['refuelings']);
        $this->assertSame([['kind' => 'mot', 'date' => '2026-11-20'], ['kind' => 'insurance', 'date' => '2026-12-01']], $facts['upcoming']);
    }

    public function test_early_in_a_month_the_facts_are_of_the_previous_month()
    {
        $vehicle = $this->vehicle();
        $this->travelTo('2026-11-05 12:00:00');

        $facts = app(MonthlySummaryFacts::class)->for($vehicle);

        $this->assertSame(['kind' => 'previous_month', 'month' => '2026-10', 'start' => '2026-10-01', 'end' => '2026-10-31', 'days' => 31, 'days_in_month' => 31], $facts['period']);
    }

    public function test_the_distance_between_two_readings_is_not_an_estimate()
    {
        $vehicle = $this->vehicle();
        Refueling::factory()->for($vehicle)->create(['date' => '2026-10-31', 'mileage_begin' => 22600, 'mileage_end' => 23200, 'charge_time' => null]);
        $this->travelTo('2026-11-02 12:00:00');

        $facts = app(MonthlySummaryFacts::class)->for($vehicle->fresh());

        $this->assertSame(1200, $facts['distance']['km']);
        $this->assertFalse($facts['distance']['estimated']);
    }

    public function test_the_summary_is_written_in_the_language_of_the_user()
    {
        MonthlySummarizer::fake([$this->answer()]);

        $summary = app(MonthlySummaryService::class)->generate($this->vehicle(), 'nl');

        $this->assertSame('Een rustige maand', $summary->headline);
        $this->assertSame('2026-10', $summary->month);
        $this->assertSame(VehicleSummary::MONTH_TO_DATE, $summary->kind);
        MonthlySummarizer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"Maintenance": 250')
            && str_contains($prompt->agent->instructions(), 'Dutch'));
    }

    public function test_the_dashboard_shows_the_figures_and_writes_the_summary_once()
    {
        MonthlySummarizer::fake([$this->answer()]);
        $this->vehicle();
        $this->withoutDefer();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('summary', fn (Assert $reload) => $reload
                    ->where('monthlySummary.title', 'This month')
                    ->where('monthlySummary.period', 'October 2026')
                    ->where('monthlySummary.figures.0.value', '± 865 km')
                    ->where('monthlySummary.figures.1.value', '€ 360')
                    ->where('monthlySummary.figures.1.details', ['Maintenance € 250'])
                    ->where('monthlySummary.upcoming.0.label', 'MOT')
                    ->etc()));

        // Written after the response; the next time it is there, and not written again.
        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('summary', fn (Assert $reload) => $reload
                    ->where('monthlySummary.summary.headline', 'Een rustige maand')
                    ->where('monthlySummary.pending', false)
                    ->etc()));

        $this->assertSame(1, VehicleSummary::count());
    }

    public function test_a_failure_is_not_retried_on_every_visit()
    {
        $tries = 0;
        MonthlySummarizer::fake(function () use (&$tries) {
            $tries++;

            throw RateLimitedException::forProvider('gemini');
        });
        $vehicle = $this->vehicle();
        $this->withoutDefer();

        app(MonthlySummaryService::class)->forDashboard($vehicle);
        app(MonthlySummaryService::class)->forDashboard($vehicle);

        $this->assertSame(1, $tries);

        $this->travel(config('monthly_summary.regenerate_after') + 1)->minutes();
        app(MonthlySummaryService::class)->forDashboard($vehicle);

        $this->assertSame(2, $tries);
    }

    public function test_the_model_is_not_asked_when_nothing_was_recorded()
    {
        MonthlySummarizer::fake()->preventStrayPrompts();
        $vehicle = $this->signInWithVehicle();
        $this->withoutDefer();

        $dashboard = app(MonthlySummaryService::class)->forDashboard($vehicle->fresh());

        $this->assertFalse($dashboard['hasRecords']);
        $this->assertFalse($dashboard['pending']);
        $this->assertSame([], $dashboard['figures']);
        MonthlySummarizer::assertNeverPrompted();
    }
}
