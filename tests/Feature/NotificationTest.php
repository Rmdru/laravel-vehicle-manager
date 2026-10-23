<?php

namespace Tests\Feature;

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleNotification;
use App\Services\NotificationCenter;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 12:00:00');
    }

    /**
     * A vehicle whose tire pressure was checked 2 months less 5 days ago: the
     * check is due within the week.
     */
    private function vehicle(): Vehicle
    {
        $vehicle = $this->signInWithVehicle(['mileage_latest' => 5000]);
        Maintenance::factory()->for($vehicle)->create(['type_maintenance' => 'tire_pressure_checked', 'date' => '2026-08-28', 'mileage' => 4000, 'tasks' => null, 'airco_check' => false]);

        return $vehicle->fresh();
    }

    private function tirePressure(Vehicle $vehicle): ?VehicleNotification
    {
        return VehicleNotification::query()->where('vehicle_id', $vehicle->id)->where('key', 'maintenance_plan:tire_pressure')->latest('id')->first();
    }

    public function test_a_notification_is_stored_and_can_be_ticked_off_from_the_dashboard()
    {
        $vehicle = $this->vehicle();

        $notification = collect(app(NotificationCenter::class)->forDashboard($vehicle))->firstWhere('key', 'maintenance_plan:tire_pressure');

        $this->assertSame('info', $notification['type']);
        $this->assertSame(route('maintenance-plan.done', 'tire_pressure'), $notification['doneUrl']);
        $this->assertNotNull($this->tirePressure($vehicle));

        $this->post($notification['doneUrl'])->assertRedirect();

        // Checked today: it no longer applies, and stays in the history.
        $this->assertNotNull($this->tirePressure($vehicle)->resolved_at);
        $this->assertSame('tire_pressure_checked', $vehicle->maintenances()->latest('date')->first()->type_maintenance);
    }

    public function test_a_dismissed_notification_comes_back_when_it_changes()
    {
        $vehicle = $this->vehicle();
        $center = app(NotificationCenter::class);
        $keys = fn () => array_column($center->forDashboard($vehicle->fresh()), 'key');

        $center->sync($vehicle);
        $this->post(route('notifications.dismiss', $this->tirePressure($vehicle)))->assertRedirect();

        $this->assertNotContains('maintenance_plan:tire_pressure', $keys());
        $this->assertSame(0, collect($center->bell($vehicle, null)['items'])->where('state', 'active')->where('text', $this->tirePressure($vehicle)->text)->count());

        // Overdue now: a warning rather than information, so it is back.
        $this->travel(10)->days();

        $this->assertContains('maintenance_plan:tire_pressure', $keys());
        $this->assertSame('warning', $this->tirePressure($vehicle)->type);
        $this->assertNull($this->tirePressure($vehicle)->dismissed_at);
    }

    public function test_the_bell_and_the_page_show_the_notifications_of_the_current_vehicle()
    {
        $vehicle = $this->vehicle();
        app(NotificationCenter::class)->sync($vehicle);
        $this->post(route('notifications.dismiss', $this->tirePressure($vehicle)));

        $this->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications')
                ->where('notifications.dismissed.0.state', 'dismissed')
                ->where('notificationBell.items', fn ($items) => collect($items)->contains('state', 'dismissed')));

        $this->post(route('notifications.restore', $this->tirePressure($vehicle)))->assertRedirect();

        $this->assertNull($this->tirePressure($vehicle)->dismissed_at);
    }

    public function test_the_bell_only_counts_what_is_new_since_it_was_opened()
    {
        $vehicle = $this->vehicle();
        $center = app(NotificationCenter::class);
        $count = fn () => $center->bell($vehicle, $vehicle->user->fresh())['count'];
        $center->sync($vehicle);

        $this->assertGreaterThan(0, $before = $count());

        $this->post(route('notifications.seen'))->assertRedirect();

        $this->assertSame(0, $count());

        // Overdue now: changed, so new again.
        $this->travel(10)->days();
        $center->sync($vehicle->fresh());

        $this->assertSame(1, $count());
    }

    public function test_a_notification_of_another_owner_cannot_be_dismissed()
    {
        $other = Vehicle::factory()->create();
        $notification = VehicleNotification::create([
            'vehicle_id' => $other->id, 'key' => 'apk', 'type' => 'critical', 'text' => 'MOT', 'payload' => [],
            'fingerprint' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $this->signInWithVehicle();

        $this->post(route('notifications.dismiss', $notification))->assertNotFound();
    }

    public function test_the_quick_add_only_offers_pages_to_add_with()
    {
        $this->signInWithVehicle();

        $items = collect(Navigation::groups())->flatMap(fn (array $group) => $group['items'])->keyBy('href');

        $this->assertNull($items['/maintenance-plan']['create']);
        $this->assertNull($items['/trips']['create']);
        $this->assertSame('/trips/create', $items['/trips/create']['create']);
        $this->assertSame('/maintenances/create', $items['/maintenances']['create']);
    }
}
