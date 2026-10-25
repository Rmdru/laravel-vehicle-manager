<?php

namespace Tests\Feature;

use App\Ai\Agents\ComplaintDiagnoser;
use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\DashboardService;
use App\Services\FindingService;
use App\Services\VehicleAdviceFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class FindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-27 12:00:00');
        config(['ai.providers.gemini.key' => 'test-key']);
    }

    public function test_a_finding_can_be_added_and_is_open_on_its_page()
    {
        $vehicle = $this->signInWithVehicle();

        $this->post(route('findings.store'), [
            'source' => 'garage_advice',
            'title' => 'Shock absorbers front leak',
            'severity' => 'soon',
            'date' => '2026-10-20',
            'garage' => 'Garage Jansen',
            'estimated_cost' => 450,
            'components' => [],
            'for_garage' => true,
            'status' => 'open',
        ])->assertSessionHasNoErrors()->assertRedirect(route('findings.index'));

        $finding = $vehicle->findings()->sole();

        $this->assertSame(FindingStatus::Open, $finding->status);
        $this->assertNull($finding->resolved_date);
        $this->assertTrue($finding->for_garage);

        $this->get(route('findings.show', $finding->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Crud/Show')
                ->where('title', 'Shock absorbers front leak')
                // Not a complaint: nothing to diagnose.
                ->has('actions', 2));
    }

    public function test_a_finding_marked_resolved_gets_a_date_and_leaves_the_list_for_the_garage()
    {
        $vehicle = $this->signInWithVehicle();
        $finding = Finding::factory()->for($vehicle)->create(['for_garage' => true]);

        $this->patch(route('findings.update', $finding->id), [
            'source' => 'garage_advice',
            'title' => $finding->title,
            'severity' => 'soon',
            'date' => $finding->date->toDateString(),
            'for_garage' => true,
            'status' => 'resolved',
        ])->assertSessionHasNoErrors();

        $finding->refresh();
        $this->assertSame('2026-10-27', $finding->resolved_date->toDateString());
        $this->assertFalse($finding->for_garage);

        $this->post(route('findings.resolve', Finding::factory()->for($vehicle)->create()->id))->assertSessionHasNoErrors();
        $this->assertSame(0, $vehicle->findings()->open()->count());
    }

    public function test_the_open_findings_come_first_in_the_list()
    {
        $vehicle = $this->signInWithVehicle();
        Finding::factory()->for($vehicle)->create(['status' => 'resolved', 'date' => '2026-10-26']);
        $open = Finding::factory()->for($vehicle)->create(['date' => '2026-01-01']);

        $this->get(route('findings.index'))
            ->assertInertia(fn (Assert $page) => $page->where('table.data.0.id', $open->id)->etc());
    }

    public function test_findings_do_not_show_under_needs_attention()
    {
        $vehicle = $this->signInWithVehicle();
        Finding::factory()->for($vehicle)->create(['severity' => 'urgent']);
        Finding::factory()->for($vehicle)->create(['severity' => 'soon']);

        $notifications = collect(app(DashboardService::class)->notifications($vehicle->fresh()));

        $this->assertCount(0, $notifications->filter(fn ($notification) => str_starts_with($notification['key'], 'finding:')));
    }

    public function test_the_defects_of_a_recent_mot_are_imported_once_for_information()
    {
        $vehicle = $this->signInWithVehicle(['rdw_data' => ['inspection' => [
            'date' => '2026-09-15',
            'type' => 'Periodieke controle',
            'defects' => [
                ['code' => 'AC1', 'article' => '5.*.55', 'description' => 'band(en) onvoldoende profiel', 'count' => 2],
                ['code' => 'RA2', 'article' => '', 'description' => 'remschijf/-trommel licht ingesleten', 'count' => 1],
            ],
        ]]]);
        $findings = app(FindingService::class);

        $this->assertSame(2, $findings->importMot($vehicle));

        $tires = $vehicle->findings()->where('rdw_code', 'AC1')->sole();
        $this->assertSame('mot_defect', $tires->source->value);
        $this->assertSame('info', $tires->severity->value);
        $this->assertSame('Band(en) onvoldoende profiel', $tires->title);
        $this->assertSame('2026-09-15', $tires->date->toDateString());

        // Deleted by the owner, it does not come back.
        $tires->delete();
        $this->assertSame(0, $findings->importMot($vehicle));
        $this->assertSame(1, $vehicle->findings()->count());
    }

    public function test_the_defects_of_an_mot_long_ago_are_not_imported()
    {
        $vehicle = $this->signInWithVehicle(['rdw_data' => ['inspection' => [
            'date' => '2025-06-01',
            'type' => 'Periodieke controle',
            'defects' => [['code' => 'AC1', 'article' => '', 'description' => 'band', 'count' => 1]],
        ]]]);

        $this->assertSame(0, app(FindingService::class)->importMot($vehicle));
    }

    public function test_a_maintenance_resolves_the_findings_that_were_ticked()
    {
        $vehicle = $this->signInWithVehicle();
        $brakes = Finding::factory()->for($vehicle)->create(['title' => 'Brakes squeal', 'for_garage' => true]);
        $other = Finding::factory()->for($vehicle)->create(['title' => 'Rattle']);

        $this->get(route('maintenances.create'))
            ->assertInertia(fn (Assert $page) => $page->where('form.fields', fn ($fields) => collect($fields)->contains('name', 'findings'))->etc());

        $this->post(route('maintenances.store'), [
            'date' => '2026-10-26',
            'mileage' => 1200,
            'total_price' => 180,
            'findings' => [$brakes->id],
        ])->assertSessionHasNoErrors();

        $maintenance = $vehicle->maintenances()->sole();
        $brakes->refresh();

        $this->assertSame(FindingStatus::Resolved, $brakes->status);
        $this->assertSame($maintenance->id, $brakes->maintenance_id);
        $this->assertSame('2026-10-26', $brakes->resolved_date->toDateString());
        $this->assertFalse($brakes->for_garage);
        $this->assertSame(FindingStatus::Open, $other->fresh()->status);

        // Unticked when the maintenance is changed, it is open again.
        $this->patch(route('maintenances.update', $maintenance->id), [
            'date' => '2026-10-26',
            'mileage' => 1200,
            'total_price' => 180,
            'findings' => [$other->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(FindingStatus::Open, $brakes->fresh()->status);
        $this->assertNull($brakes->fresh()->maintenance_id);
        $this->assertSame(FindingStatus::Resolved, $other->fresh()->status);
    }

    public function test_the_list_for_the_garage_shows_what_is_on_it_and_what_is_due()
    {
        $vehicle = $this->signInWithVehicle(['construction_date' => '2015-01-01', 'mileage_latest' => 150000]);
        $listed = Finding::factory()->for($vehicle)->create(['for_garage' => true, 'title' => 'Squeaking belt']);
        Finding::factory()->for($vehicle)->create(['title' => 'Paint chip']);
        // Brake fluid every 2 years, last done 3 years ago: overdue.
        Maintenance::factory()->for($vehicle)->create(['date' => '2023-10-01', 'mileage' => 120000, 'tasks' => [['task' => 'Brake fluid', 'price' => 80, 'components' => ['brake_fluid']]]]);

        $this->get(route('for-the-garage'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ForTheGarage')
                ->has('items', 1)
                ->where('items.0.id', $listed->id)
                ->has('others', 1)
                ->where('plan', fn ($plan) => collect($plan)->contains('key', 'brake_fluid'))
                ->where('recordUrl', route('maintenances.create', ['for_garage' => 1])));

        $this->post(route('for-the-garage.plan', 'brake_fluid'))->assertSessionHasNoErrors();

        $fluid = $vehicle->findings()->whereJsonContains('components', 'brake_fluid')->sole();
        $this->assertSame('maintenance_plan', $fluid->source->value);
        $this->assertTrue($fluid->for_garage);

        // Recorded from the list: what is on it, as tasks, ticked as resolved.
        $this->get(route('maintenances.create', ['for_garage' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.defaults.findings', [$listed->id, $fluid->id])
                ->where('form.defaults.tasks.1.components', ['brake_fluid']));
    }

    public function test_own_lines_go_on_the_list_for_the_garage_and_off_it_with_the_maintenance()
    {
        $vehicle = $this->signInWithVehicle();

        $this->post(route('for-the-garage.lines.store'), ['text' => 'Vraag naar de prijs van nieuwe ruitenwissers'])->assertSessionHasNoErrors();
        $this->post(route('for-the-garage.lines.store'), ['text' => ''])->assertSessionHasErrors('text');

        $line = $vehicle->garageListItems()->sole();
        $this->assertSame(0, $vehicle->findings()->count());

        $this->get(route('for-the-garage'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lines', 1)
                ->where('lines.0.text', 'Vraag naar de prijs van nieuwe ruitenwissers'));

        // Recorded from the list, it is a task of the maintenance.
        $this->get(route('maintenances.create', ['for_garage' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.defaults.tasks.0.task', 'Vraag naar de prijs van nieuwe ruitenwissers')
                ->where('form.defaults.garage_lines', (string) $line->id));

        $this->post(route('maintenances.store'), [
            'date' => '2026-10-26',
            'mileage' => 1200,
            'total_price' => 40,
            'garage_lines' => (string) $line->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $vehicle->garageListItems()->count());
    }

    public function test_an_own_line_can_be_removed_but_not_one_of_another_vehicle()
    {
        $vehicle = $this->signInWithVehicle();
        $line = $vehicle->garageListItems()->create(['text' => 'Bougies meenemen']);
        $other = Vehicle::factory()->create()->garageListItems()->create(['text' => 'Niet van mij']);

        $this->delete(route('for-the-garage.lines.destroy', $other->id))->assertNotFound();
        $this->delete(route('for-the-garage.lines.destroy', $line->id));

        $this->assertSame(0, $vehicle->garageListItems()->count());
        $this->assertNotNull($other->fresh());
    }

    public function test_a_finding_can_be_put_on_the_list_for_the_garage_and_taken_off()
    {
        $vehicle = $this->signInWithVehicle();
        $finding = Finding::factory()->for($vehicle)->create();

        $this->post(route('findings.garage', $finding->id));
        $this->assertTrue($finding->fresh()->for_garage);

        $this->post(route('findings.garage', $finding->id));
        $this->assertFalse($finding->fresh()->for_garage);
    }

    public function test_a_complaint_can_be_diagnosed_by_ai()
    {
        ComplaintDiagnoser::fake([[
            'causes' => [
                ['cause' => 'Versleten remblokken', 'likelihood' => 'high', 'explanation' => 'Piepen bij remmen past bij dunne blokken.', 'cost_range' => '€ 120 - € 200'],
                ['cause' => 'Roest op de schijven', 'likelihood' => 'nonsense', 'explanation' => 'Na stilstaan.', 'cost_range' => ''],
            ],
            'driving' => 'careful',
            'driving_explanation' => 'Rustig doorrijden kan.',
            'what_to_tell_the_garage' => 'Het piept bij zacht remmen.',
            'self_checks' => ['Kijk door de velg naar de blokken.'],
        ]]);

        $vehicle = $this->signInWithVehicle(['brand' => 'Toyota', 'model' => 'Yaris']);
        $complaint = Finding::factory()->for($vehicle)->create(['source' => 'complaint', 'title' => 'Remmen piepen', 'description' => 'Bij zacht remmen, vooral koud.']);

        $this->post(route('findings.diagnose', $complaint->id))->assertSessionHasNoErrors();

        $diagnosis = $complaint->fresh()->diagnosis;
        $this->assertSame('Versleten remblokken', $diagnosis['causes'][0]['cause']);
        // What the model made up is put right.
        $this->assertSame('medium', $diagnosis['causes'][1]['likelihood']);
        $this->assertSame('careful', $diagnosis['driving']);

        ComplaintDiagnoser::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Remmen piepen') && str_contains($prompt->prompt, 'Yaris'));

        // Not again within minutes.
        $this->assertFalse(app(FindingService::class)->canDiagnose($complaint->fresh()));

        $this->get(route('findings.show', $complaint->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sections.1.title', 'What it may be')
                ->where('actions.2.disabled', true));
    }

    public function test_only_a_complaint_can_be_diagnosed_and_a_failure_is_reported()
    {
        ComplaintDiagnoser::fake(fn () => throw new RuntimeException('Down'));

        $vehicle = $this->signInWithVehicle();
        $advice = Finding::factory()->for($vehicle)->create(['source' => 'garage_advice']);
        $complaint = Finding::factory()->for($vehicle)->create(['source' => 'complaint']);

        $this->post(route('findings.diagnose', $advice->id))->assertNotFound();
        $this->post(route('findings.diagnose', $complaint->id))->assertSessionHasNoErrors();

        $this->assertNull($complaint->fresh()->diagnosis);
    }

    public function test_the_tips_know_the_open_findings()
    {
        $vehicle = $this->signInWithVehicle();
        Finding::factory()->for($vehicle)->create(['title' => 'Oil leak at the sump', 'severity' => 'soon', 'components' => ['engine_oil']]);
        Finding::factory()->for($vehicle)->create(['title' => 'Fixed already', 'status' => 'resolved']);

        $facts = app(VehicleAdviceFacts::class)->for($vehicle->fresh());

        $this->assertSame(['Oil leak at the sump'], array_column($facts['open_findings'], 'title'));
        $this->assertSame(['engine_oil'], $facts['open_findings'][0]['components']);
    }
}
