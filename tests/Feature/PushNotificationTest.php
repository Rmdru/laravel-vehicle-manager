<?php

namespace Tests\Feature;

use App\Models\Maintenance;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Models\VehicleAdvice;
use App\Models\VehicleNotification;
use App\Services\NotificationCenter;
use App\Services\Push\PushOutbox;
use App\Services\Push\PushSender;
use App\Services\Push\PushTiming;
use App\Services\Push\PushTransport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** What was sent, instead of the push services. */
    private PushTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        // In winter time: Amsterdam is an hour ahead of UTC.
        $this->travelTo('2026-11-10 10:00:00');

        $this->transport = new class extends PushTransport
        {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            /** @var list<int> The devices the push service no longer knows. */
            public array $gone = [];

            public function send(Collection $subscriptions, array $payload, string $urgency, string $topic): array
            {
                $ids = $subscriptions->modelKeys();
                $this->sent[] = ['payload' => $payload, 'urgency' => $urgency, 'devices' => $ids];

                return [
                    'delivered' => array_values(array_diff($ids, $this->gone)),
                    'expired' => array_values(array_intersect($ids, $this->gone)),
                ];
            }
        };
        $this->app->instance(PushTransport::class, $this->transport);
    }

    private function subscribe(Vehicle $vehicle, string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-1'): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $vehicle->user_id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hash($endpoint),
            'public_key' => 'key',
            'auth_token' => 'auth',
        ]);
    }

    /**
     * A notification of the bell that just appeared, put in line to be pushed.
     */
    private function notification(Vehicle $vehicle, string $type = 'warning', string $text = 'MOT expires within 1 month!', array $payload = []): VehicleNotification
    {
        $notification = VehicleNotification::create([
            'vehicle_id' => $vehicle->id, 'key' => 'apk:'.uniqid(), 'type' => $type, 'text' => $text,
            'payload' => ['icon' => 'ic:baseline-security', 'url' => '/maintenances', ...$payload],
            'fingerprint' => VehicleNotification::fingerprint($type, $text),
            'first_seen_at' => now(), 'last_seen_at' => now(), 'notified_at' => now(),
        ]);
        app(PushOutbox::class)->notification($notification);

        return $notification;
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'UTC');
    }

    public function test_something_critical_goes_at_once_and_the_rest_at_the_moment_the_same_day()
    {
        $vehicle = $this->signInWithVehicle();
        $timing = app(PushTiming::class);
        $sendAfter = fn (string $urgency, string $now) => $timing->sendAfter($vehicle->fresh(), $urgency, $this->at($now))->toDateTimeString();

        // 11:00 in Amsterdam: critical at once, the rest at 18:00 (too few records to know the moment of the owner).
        $this->assertSame('2026-11-10 10:00:00', $sendAfter('critical', '2026-11-10 10:00:00'));
        $this->assertSame('2026-11-10 17:00:00', $sendAfter('warning', '2026-11-10 10:00:00'));
        $this->assertSame('2026-11-10 17:00:00', $sendAfter('info', '2026-11-10 10:00:00'));

        // 20:00: after the moment, so at once, the same day.
        $this->assertSame('2026-11-10 19:00:00', $sendAfter('warning', '2026-11-10 19:00:00'));

        // 22:30 and 03:30, in the quiet hours: not even critical, until 08:00.
        $this->assertSame('2026-11-11 07:00:00', $sendAfter('critical', '2026-11-10 21:30:00'));
        $this->assertSame('2026-11-11 07:00:00', $sendAfter('warning', '2026-11-10 21:30:00'));
        $this->assertSame('2026-11-10 07:00:00', $sendAfter('critical', '2026-11-10 02:30:00'));
        $this->assertSame('2026-11-10 17:00:00', $sendAfter('warning', '2026-11-10 02:30:00'));

        // A moment of their own, and no quiet hours.
        $vehicle->update(['push_preferences' => ['moment' => '07:30', 'quiet_from' => null, 'quiet_until' => null]]);

        $this->assertSame('2026-11-10 06:30:00', $sendAfter('warning', '2026-11-10 02:30:00'));
        $this->assertSame('2026-11-10 21:30:00', $sendAfter('critical', '2026-11-10 21:30:00'));
    }

    public function test_the_moment_is_the_hour_the_owner_enters_most_records_in()
    {
        $vehicle = $this->signInWithVehicle();

        foreach (['2026-10-01 18:10', '2026-10-05 18:40', '2026-10-09 18:05', '2026-10-14 18:55', '2026-10-20 07:15', '2026-10-28 19:20'] as $time) {
            Refueling::factory()->for($vehicle)->create()->forceFill(['created_at' => $time])->save();
        }

        // 18:xx UTC is 20:xx in Amsterdam in summer time, and 19:20 UTC too in winter time.
        $this->assertSame('20:00', app(PushTiming::class)->usualMoment($vehicle));
        $this->assertSame('2026-11-10 19:00:00', app(PushTiming::class)->sendAfter($vehicle, 'warning')->toDateTimeString());
    }

    public function test_the_nightly_sync_puts_new_notifications_in_line_for_owners_with_a_device()
    {
        $vehicle = $this->signInWithVehicle(['mileage_latest' => 5000, 'construction_date' => '2015-01-01']);
        Maintenance::factory()->for($vehicle)->create(['type_maintenance' => 'tire_pressure_checked', 'date' => '2026-09-15', 'mileage' => 4000, 'tasks' => null, 'airco_check' => false]);
        $center = app(NotificationCenter::class);

        // In the app the owner sees them: nothing to push.
        $center->sync($vehicle->fresh());
        $this->assertSame(0, PushMessage::count());

        // Without a device neither.
        $center->sync($vehicle->fresh(), push: true);
        $this->assertSame(0, PushMessage::count());

        // Changed (overdue now), with a device: in line.
        $this->subscribe($vehicle);
        $this->travel(10)->days();
        $center->sync($vehicle->fresh(), push: true);

        $tirePressure = VehicleNotification::query()->where('key', 'maintenance_plan:tire_pressure')->whereNull('resolved_at')->sole();
        $message = PushMessage::query()->where('vehicle_notification_id', $tirePressure->id)->sole();
        $this->assertSame('critical', $message->urgency);
        $this->assertTrue(str_contains($message->body, $vehicle->license_plate));
    }

    public function test_what_no_longer_applies_or_was_seen_in_the_app_is_not_sent()
    {
        $vehicle = $this->signInWithVehicle();
        $this->subscribe($vehicle);

        $this->notification($vehicle, 'info', 'Seen');
        // Looked at the bell after it appeared.
        $vehicle->user->forceFill(['notifications_seen_at' => now()->addMinute()])->save();

        $this->travel(5)->minutes();
        $sent = $this->notification($vehicle, 'critical', 'Vehicle is not insured!', ['doneUrl' => null]);
        $this->notification($vehicle, 'warning', 'Dismissed')->update(['dismissed_at' => now()]);
        $this->notification($vehicle, 'warning', 'Resolved')->update(['resolved_at' => now()]);

        $this->travelTo('2026-11-10 17:00:00');

        $this->assertSame(1, app(PushSender::class)->sendDue());
        $this->assertCount(1, $this->transport->sent);

        $payload = $this->transport->sent[0]['payload'];
        $this->assertSame('🔴 Vehicle is not insured!', $payload['title']);
        $this->assertSame('critical', $this->transport->sent[0]['urgency']);
        $this->assertSame(['dismiss'], array_column($payload['actions'], 'action'));
        $this->assertSame('notification-'.$sent->id, $payload['tag']);
        $this->assertStringEndsWith('push-icons/badge.png', $payload['badge']);
        $this->assertSame(3, PushMessage::query()->whereNotNull('cancelled_at')->count());

        // Sent once.
        $this->assertSame(0, app(PushSender::class)->sendDue());
    }

    public function test_several_at_once_go_as_one()
    {
        $vehicle = $this->signInWithVehicle();
        $this->subscribe($vehicle);

        foreach (['One', 'Two', 'Three'] as $text) {
            $this->notification($vehicle, 'info', $text);
        }
        $this->notification($vehicle, 'warning', 'Four');

        $this->travelTo('2026-11-10 17:00:00');
        app(PushSender::class)->sendDue();

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('🟠 4 new notifications', $this->transport->sent[0]['payload']['title']);
        $this->assertStringStartsWith('🟠 Four', $this->transport->sent[0]['payload']['body']);
        $this->assertSame(4, PushMessage::query()->whereNotNull('sent_at')->count());
    }

    public function test_a_device_the_push_service_no_longer_knows_is_removed()
    {
        $vehicle = $this->signInWithVehicle();
        $gone = $this->subscribe($vehicle);
        $kept = $this->subscribe($vehicle, 'https://fcm.googleapis.com/fcm/send/device-2');
        $this->transport->gone = [$gone->id];

        $this->notification($vehicle, 'critical', 'Open recall!');
        app(PushSender::class)->sendDue();

        $this->assertModelMissing($gone);
        $this->assertNotNull($kept->fresh()->last_used_at);
    }

    public function test_the_owner_chooses_per_vehicle_what_is_sent()
    {
        $vehicle = $this->signInWithVehicle(['push_preferences' => ['info' => false]]);
        $this->subscribe($vehicle);

        $this->notification($vehicle, 'info', 'MOT expires within 2 months!');
        $this->assertSame(0, PushMessage::count());

        $this->notification($vehicle, 'warning', 'MOT expires within 1 month!');
        $this->assertSame(1, PushMessage::count());

        // Turned off afterwards: not sent either.
        $vehicle->update(['push_preferences' => ['warning' => false]]);
        $this->travelTo('2026-11-10 17:00:00');

        $this->assertSame(0, app(PushSender::class)->sendDue());
    }

    public function test_new_tips_and_the_summary_of_the_month_are_put_in_line()
    {
        $vehicle = $this->signInWithVehicle();
        $this->subscribe($vehicle);
        $tip = fn (string $key, string $priority = 'medium') => [
            'key' => $key, 'category' => 'maintenance', 'icon' => 'mdi:car-battery', 'priority' => $priority, 'basis' => 'general_knowledge',
            'action' => 'none', 'due_date' => null, 'title' => 'Tip '.$key, 'body' => 'Body',
        ];
        $previous = VehicleAdvice::create(['vehicle_id' => $vehicle->id, 'locale' => 'en', 'suggestions' => [$tip('battery')], 'facts' => [], 'facts_hash' => 'a', 'model' => 'm']);
        $advice = VehicleAdvice::create(['vehicle_id' => $vehicle->id, 'locale' => 'en', 'suggestions' => [$tip('battery'), $tip('wipers'), $tip('brakes', 'high')], 'facts' => [], 'facts_hash' => 'b', 'model' => 'm']);

        // The urgent one is a notification of the bell already.
        $message = app(PushOutbox::class)->tips($vehicle, $advice, $previous);
        $this->assertSame('New tip: Tip wipers', $message->title);
        $this->assertSame('mdi:car-battery', $message->icon);
        $this->assertNull(app(PushOutbox::class)->tips($vehicle, $advice, $advice));

        $this->artisan('push:monthly-summaries')->assertSuccessful();
        $this->assertSame('Your car in October', PushMessage::query()->where('kind', PushMessage::SUMMARY)->sole()->title);
    }

    public function test_a_button_of_the_notification_works_without_the_app_but_only_signed()
    {
        $vehicle = $this->signInWithVehicle();
        $this->subscribe($vehicle);
        $notification = $this->notification($vehicle, 'critical', 'Open recall!');
        $message = PushMessage::query()->sole();
        auth()->logout();

        $this->post(route('push.action', [$message, 'dismiss']))->assertForbidden();

        $this->post(URL::temporarySignedRoute('push.action', now()->addDay(), [$message, 'dismiss']))->assertNoContent();
        $this->assertNotNull($notification->fresh()->dismissed_at);
    }

    public function test_a_tapped_notification_opens_the_app_with_its_vehicle()
    {
        $vehicle = $this->signInWithVehicle();
        $this->subscribe($vehicle);
        $this->notification($vehicle, 'critical', 'Open recall!');
        $message = PushMessage::query()->sole();

        $this->get(route('push.open', $message))
            ->assertRedirect('/maintenances')
            ->assertSessionHas('current_vehicle_id', $vehicle->id);
        $this->get(route('push.open', [$message, 'to' => 'notifications']))->assertRedirect(route('notifications.index'));

        $this->signInWithVehicle();
        $this->get(route('push.open', $message))->assertNotFound();
    }

    public function test_a_device_turns_push_notifications_on_and_off_and_gets_a_test()
    {
        $vehicle = $this->signInWithVehicle();
        $device = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'key', 'auth' => 'auth'], 'contentEncoding' => 'aes128gcm'];

        $this->postJson(route('push-subscriptions.store'), $device)->assertNoContent();
        // Renewed: still one.
        $this->postJson(route('push-subscriptions.store'), $device)->assertNoContent();
        $this->assertSame(1, $vehicle->user->pushSubscriptions()->count());

        $this->post(route('push.test'))->assertRedirect();
        $this->assertSame('🔵 Test notification', $this->transport->sent[0]['payload']['title']);

        $this->get(route('notifications.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Notifications')
                ->has('devices', 1)
                ->where('vehicles.0.moment', '18:00')
                ->where('vehicles.0.quietFrom', '21:00'));

        $this->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $device['endpoint']])->assertNoContent();
        $this->assertSame(0, $vehicle->user->pushSubscriptions()->count());
    }

    public function test_the_push_notifications_are_sent_every_five_minutes()
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'push:send'));

        $this->assertSame('*/5 * * * *', $event?->expression);
    }
}
