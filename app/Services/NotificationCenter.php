<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatusNotificationOk;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleNotification;
use App\Services\Push\PushOutbox;
use App\Support\StatusNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Keeps the notifications of a vehicle: what "Needs attention" shows, stored
 * as they come and go, so that the owner can dismiss one and look back on
 * them. A dismissed notification comes back when it changes, such as when it
 * becomes more urgent.
 */
class NotificationCenter
{
    /** Notifications in the bell, at most. */
    private const BELL = 10;

    /** Notifications in the history on the page, at most. */
    private const HISTORY = 100;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly PushOutbox $push,
    ) {
        //
    }

    /**
     * Brings the stored notifications in line with what applies now: new ones
     * are added, changed ones updated (and shown again when dismissed), and
     * the ones that no longer apply are resolved. The open ones, by key.
     *
     * With push, the new and changed ones are put in line to be sent to the
     * devices of the owner; not when the owner is in the app and sees them.
     *
     * @return Collection<string, VehicleNotification>
     */
    public function sync(Vehicle $vehicle, bool $push = false): Collection
    {
        $current = collect($this->dashboard->notifications($vehicle))
            ->reject(fn (array $notification) => $notification['type'] === 'success')
            ->keyBy('key');
        $open = VehicleNotification::query()->where('vehicle_id', $vehicle->id)->open()->get()->keyBy('key');
        $now = now();

        foreach ($open->diffKeys($current) as $notification) {
            $notification->update(['resolved_at' => $now]);
        }

        return $current->map(function (array $notification, string $key) use ($open, $vehicle, $now, $push) {
            $fingerprint = VehicleNotification::fingerprint($notification['type'], $notification['text']);
            $stored = $open->get($key) ?? new VehicleNotification(['vehicle_id' => $vehicle->id, 'key' => $key, 'first_seen_at' => $now, 'notified_at' => $now]);
            $new = ! $stored->exists;
            $changed = $stored->exists && $stored->fingerprint !== $fingerprint;

            $stored->fill([
                'type' => $notification['type'],
                'text' => mb_substr($notification['text'], 0, 255),
                'payload' => $notification,
                'fingerprint' => $fingerprint,
                'last_seen_at' => $now,
                // Changed, it is new again: shown when dismissed, and counted in the bell.
                ...($changed ? ['dismissed_at' => null, 'notified_at' => $now] : []),
            ])->save();

            if ($push && ($new || $changed)) {
                $this->push->notification($stored->setRelation('vehicle', $vehicle));
            }

            return $stored;
        });
    }

    /**
     * The notifications for "Needs attention": the ones that apply and are not
     * dismissed, with how to dismiss them. A cheerful one when there are none.
     *
     * @return list<array<string, mixed>>
     */
    public function forDashboard(Vehicle $vehicle): array
    {
        $visible = $this->sync($vehicle)
            ->filter(fn (VehicleNotification $notification) => $notification->dismissed_at === null)
            ->map(fn (VehicleNotification $notification) => [
                ...$notification->payload,
                'id' => $notification->id,
                'dismissUrl' => route('notifications.dismiss', $notification),
            ])
            ->sortBy('priority')
            ->values()
            ->all();

        if ($visible !== []) {
            return $visible;
        }

        $types = StatusNotification::types();

        return [[
            ...$types['success'],
            'key' => 'ok',
            'type' => 'success',
            'text' => collect(StatusNotificationOk::cases())->map(fn ($case) => $case->getLabel())->random(),
            'icon' => 'fa7-solid:face-smile',
            'typeIcon' => $types['success']['icon'],
            'hasModal' => false,
            'data' => [],
        ]];
    }

    public function dismiss(VehicleNotification $notification): void
    {
        $notification->update(['dismissed_at' => now()]);
    }

    public function restore(VehicleNotification $notification): void
    {
        $notification->update(['dismissed_at' => null]);
    }

    /**
     * The bell in the navigation: how many notifications are new since the
     * user last looked, and the latest ones: those that apply first, then the
     * dismissed ones, then the ones that no longer apply.
     *
     * @return array<string, mixed>
     */
    public function bell(Vehicle $vehicle, ?User $user): array
    {
        $open = $this->query($vehicle)->open()->get();
        $active = $open->whereNull('dismissed_at');
        $seen = $user?->notifications_seen_at;
        $items = $this->sorted($active)
            ->concat($open->whereNotNull('dismissed_at')->sortByDesc('dismissed_at'))
            ->concat($this->query($vehicle)->whereNotNull('resolved_at')->latest('resolved_at')->limit(self::BELL)->get())
            ->take(self::BELL);

        return [
            'count' => $active->filter(fn (VehicleNotification $notification) => $seen === null || $notification->notified_at?->gt($seen))->count(),
            'items' => $items->map(fn (VehicleNotification $notification) => $this->present($notification))->values()->all(),
            'url' => route('notifications.index'),
            'seenUrl' => route('notifications.seen'),
        ];
    }

    /**
     * The user looked at the notifications: the bell counts the ones after this.
     */
    public function seen(User $user): void
    {
        $user->forceFill(['notifications_seen_at' => now()])->save();
    }

    /**
     * All notifications for the page: the ones that apply, the dismissed ones,
     * and the history of the ones that no longer apply.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function overview(Vehicle $vehicle): array
    {
        $open = $this->query($vehicle)->open()->get();
        $present = fn (Collection $notifications) => $notifications->map(fn (VehicleNotification $notification) => $this->present($notification))->values()->all();

        return [
            'active' => $present($this->sorted($open->whereNull('dismissed_at'))),
            'dismissed' => $present($open->whereNotNull('dismissed_at')->sortByDesc('dismissed_at')),
            'history' => $present($this->query($vehicle)->whereNotNull('resolved_at')->latest('resolved_at')->limit(self::HISTORY)->get()),
        ];
    }

    /**
     * @return Builder<VehicleNotification>
     */
    private function query(Vehicle $vehicle)
    {
        return VehicleNotification::query()->where('vehicle_id', $vehicle->id);
    }

    /**
     * The most urgent first, then the newest.
     *
     * @param  Collection<int, VehicleNotification>  $notifications
     * @return Collection<int, VehicleNotification>
     */
    private function sorted(Collection $notifications): Collection
    {
        return $notifications->sortBy([
            fn (VehicleNotification $a, VehicleNotification $b) => ($a->payload['priority'] ?? 9) <=> ($b->payload['priority'] ?? 9),
            fn (VehicleNotification $a, VehicleNotification $b) => $b->first_seen_at <=> $a->first_seen_at,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(VehicleNotification $notification): array
    {
        $state = match (true) {
            $notification->resolved_at !== null => 'resolved',
            $notification->dismissed_at !== null => 'dismissed',
            default => 'active',
        };

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'state' => $state,
            'text' => $notification->text,
            'icon' => $notification->payload['icon'] ?? null,
            'typeIcon' => $notification->payload['typeIcon'] ?? null,
            'url' => $notification->payload['url'] ?? null,
            'urlLabel' => $notification->payload['urlLabel'] ?? null,
            'doneUrl' => $state === 'resolved' ? null : ($notification->payload['doneUrl'] ?? null),
            'since' => $notification->first_seen_at->isoFormat('D MMMM YYYY'),
            'until' => match ($state) {
                'resolved' => $notification->resolved_at->isoFormat('D MMMM YYYY'),
                'dismissed' => $notification->dismissed_at->isoFormat('D MMMM YYYY'),
                default => null,
            },
            'dismissUrl' => $state === 'active' ? route('notifications.dismiss', $notification) : null,
            'restoreUrl' => $state === 'dismissed' ? route('notifications.restore', $notification) : null,
        ];
    }
}
