<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends the push notifications whose moment has come, to every device of the
 * owner. What no longer applies, or what the owner saw in the app in the
 * meantime, is not sent. Several at once for a vehicle go as one.
 */
class PushSender
{
    use Localizable;

    /** In front of the title: the urgency at a glance, as the colors under "Needs attention". */
    private const DOTS = ['critical' => '🔴', 'warning' => '🟠', 'info' => '🔵'];

    public function __construct(
        private readonly PushTransport $transport,
        private readonly PushIcons $icons,
    ) {
        //
    }

    /**
     * Sends the messages that are due. The number sent.
     */
    public function sendDue(): int
    {
        $sent = 0;

        PushMessage::query()
            ->due()
            ->with(['vehicle.user.pushSubscriptions', 'notification'])
            ->get()
            ->groupBy('vehicle_id')
            ->each(function (Collection $messages) use (&$sent) {
                $sent += $this->sendForVehicle($messages);
            });

        return $sent;
    }

    /**
     * A message to every device of the user, to see that push notifications
     * arrive and how they look. The number of devices it reached.
     */
    public function test(User $user): int
    {
        $payload = $this->withLocale($user->language ?: config('app.locale'), fn () => [
            'title' => self::DOTS['info'].' '.__('Test notification'),
            'body' => __('Push notifications of the :app arrive on this device.', ['app' => config('app.name')]),
            'icon' => $this->icons->icon('mdi:bell-ring-outline', 'info'),
            'badge' => $this->icons->badge(),
            'tag' => 'test',
            'url' => route('notifications.settings'),
            'actions' => [],
        ]);

        return count($this->deliver($user->pushSubscriptions, $payload, 'info', 'test'));
    }

    /**
     * @param  Collection<int, PushMessage>  $messages
     */
    private function sendForVehicle(Collection $messages): int
    {
        /** @var Vehicle|null $vehicle */
        $vehicle = $messages->first()->vehicle;
        $subscriptions = $vehicle?->user->pushSubscriptions ?? collect();
        [$valid, $invalid] = $messages->partition(fn (PushMessage $message) => $vehicle !== null && $this->stillApplies($message, $vehicle));

        // Nothing to send to, or no longer of use: not sent later either.
        if ($subscriptions->isEmpty()) {
            $valid = collect();
            $invalid = $messages;
        }

        PushMessage::query()->whereKey($invalid->pluck('id')->all())->update(['cancelled_at' => now()]);

        if ($valid->isEmpty()) {
            return 0;
        }

        $valid = $valid->sortBy(fn (PushMessage $message) => array_search($message->urgency, PushMessage::URGENCIES, true))->values();
        $locale = $vehicle->user->language ?: config('app.locale');
        $deliveries = $valid->count() >= config('push.bundle_from')
            ? [[$this->withLocale($locale, fn () => $this->bundle($valid, $vehicle)), $valid->first()->urgency, 'bundle-'.$vehicle->id]]
            : $valid->map(fn (PushMessage $message) => [$this->withLocale($locale, fn () => $this->payload($message)), $message->urgency, $this->tag($message)])->all();

        foreach ($deliveries as [$payload, $urgency, $tag]) {
            $this->deliver($subscriptions, $payload, $urgency, $tag);
        }

        PushMessage::query()->whereKey($valid->pluck('id')->all())->update(['sent_at' => now()]);

        return $valid->count();
    }

    /**
     * Whether the message is still of use: the owner still wants it, and a
     * notification still applies, is not dismissed, and was not seen in the
     * app since it appeared or changed.
     */
    private function stillApplies(PushMessage $message, Vehicle $vehicle): bool
    {
        $preferences = $vehicle->pushPreferences();

        if ($message->kind !== PushMessage::NOTIFICATION) {
            return (bool) ($preferences[$message->kind] ?? false);
        }

        $notification = $message->notification;
        $seen = $vehicle->user?->notifications_seen_at;

        return $notification !== null
            && ($preferences[$notification->type] ?? false)
            && $notification->resolved_at === null
            && $notification->dismissed_at === null
            && ($seen === null || $notification->notified_at === null || $notification->notified_at->gt($seen));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PushMessage $message): array
    {
        $notification = $message->notification;
        $actions = [];

        // Ticked off and dismissed as under "Needs attention", without opening the app.
        if ($notification !== null) {
            if (($notification->payload['doneUrl'] ?? null) !== null) {
                $actions[] = ['action' => 'done', 'title' => __('Done'), 'url' => $this->actionUrl($message, 'done')];
            }

            $actions[] = ['action' => 'dismiss', 'title' => __('Dismiss'), 'url' => $this->actionUrl($message, 'dismiss')];
        }

        return [
            'title' => self::DOTS[$message->urgency].' '.$message->title,
            'body' => $message->body,
            'icon' => $this->icons->icon($message->icon, $message->urgency),
            'badge' => $this->icons->badge(),
            'tag' => $this->tag($message),
            'url' => route('push.open', $message),
            'actions' => $actions,
            'timestamp' => $message->send_after->getTimestampMs(),
        ];
    }

    /**
     * Several messages for a vehicle as one, with the most urgent on top.
     *
     * @param  Collection<int, PushMessage>  $messages
     * @return array<string, mixed>
     */
    private function bundle(Collection $messages, Vehicle $vehicle): array
    {
        $urgency = $messages->first()->urgency;

        return [
            'title' => self::DOTS[$urgency].' '.__(':count new notifications', ['count' => $messages->count()]),
            'body' => $messages
                ->map(fn (PushMessage $message) => self::DOTS[$message->urgency].' '.Str::limit($message->title, 80))
                ->push(trim($vehicle->full_name.' ('.$vehicle->license_plate.')'))
                ->implode("\n"),
            'icon' => $this->icons->icon(PushIcons::FALLBACK, $urgency),
            'badge' => $this->icons->badge(),
            'tag' => 'bundle-'.$vehicle->id,
            'url' => route('push.open', [$messages->first(), 'to' => 'notifications']),
            'actions' => [],
            'timestamp' => now()->getTimestampMs(),
        ];
    }

    /**
     * One notification on the device per subject: a newer version replaces it.
     */
    private function tag(PushMessage $message): string
    {
        return $message->vehicle_notification_id !== null
            ? 'notification-'.$message->vehicle_notification_id
            : $message->kind.'-'.$message->vehicle_id;
    }

    private function actionUrl(PushMessage $message, string $action): string
    {
        return URL::temporarySignedRoute('push.action', now()->addDays(14), ['message' => $message, 'action' => $action]);
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     * @return list<int> The devices it reached.
     */
    private function deliver(Collection $subscriptions, array $payload, string $urgency, string $tag): array
    {
        if ($subscriptions->isEmpty()) {
            return [];
        }

        $result = $this->transport->send($subscriptions, $payload, $urgency, md5($tag));

        // Unsubscribed, or the browser was removed: the push service no longer knows the device.
        PushSubscription::query()->whereKey($result['expired'])->delete();
        PushSubscription::query()->whereKey($result['delivered'])->update(['last_used_at' => now()]);

        return $result['delivered'];
    }
}
