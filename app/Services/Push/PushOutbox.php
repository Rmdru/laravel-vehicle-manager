<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\PushMessage;
use App\Models\Vehicle;
use App\Models\VehicleAdvice;
use App\Models\VehicleNotification;
use App\Support\StatusNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;

/**
 * Puts what is worth a push notification in line to be sent at its moment
 * (see PushTiming): a notification of the bell that is new or changed, new
 * tips, the monthly summary. Only for owners with a device that receives them,
 * and only what they want for the vehicle.
 */
class PushOutbox
{
    use Localizable;

    public function __construct(private readonly PushTiming $timing)
    {
        //
    }

    /**
     * A notification that appeared or changed: in its own words, which are
     * stored in the language of the owner. A version not sent yet is replaced.
     */
    public function notification(VehicleNotification $notification): ?PushMessage
    {
        $vehicle = $notification->vehicle;

        if ($vehicle === null || ! in_array($notification->type, PushMessage::URGENCIES, true) || ! $this->wants($vehicle, $notification->type)) {
            return null;
        }

        PushMessage::query()
            ->where('vehicle_notification_id', $notification->id)
            ->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()]);

        return $this->inOwnersLocale($vehicle, fn () => $this->queue($vehicle, [
            'kind' => PushMessage::NOTIFICATION,
            'urgency' => $notification->type,
            'vehicle_notification_id' => $notification->id,
            'reference' => 'notification:'.$notification->id.':'.$notification->notified_at?->getTimestamp(),
            'title' => Str::limit($notification->text, 120),
            'body' => StatusNotification::types()[$notification->type]['badgeText'].' · '.$this->name($vehicle),
            'icon' => $notification->payload['icon'] ?? null,
            'url' => $notification->payload['url'] ?? route('notifications.index'),
        ]));
    }

    /**
     * The tips of a new advice that the previous one did not have. The urgent
     * ones are left out: they are notifications of the bell of their own.
     */
    public function tips(Vehicle $vehicle, VehicleAdvice $advice, ?VehicleAdvice $previous): ?PushMessage
    {
        $before = array_column($previous->suggestions ?? [], 'key');
        $new = collect($advice->suggestions)
            ->reject(fn (array $suggestion) => in_array($suggestion['key'], $before, true) || $suggestion['priority'] === 'high')
            ->values();

        if ($new->isEmpty() || ! $this->wants($vehicle, 'tips')) {
            return null;
        }

        $first = $new->first();
        $icon = array_key_exists((string) ($first['icon'] ?? ''), VehicleAdvice::ICONS) ? $first['icon'] : 'mdi:lightbulb-on-outline';

        return $this->inOwnersLocale($vehicle, fn () => $this->queue($vehicle, [
            'kind' => PushMessage::TIPS,
            'urgency' => 'info',
            'reference' => 'tips:'.$advice->id,
            'title' => $new->count() === 1
                ? Str::limit(__('New tip: :title', ['title' => $first['title']]), 120)
                : __(':count new tips', ['count' => $new->count()]),
            'body' => $new->pluck('title')->take(3)->push($this->name($vehicle))->implode("\n"),
            'icon' => $icon,
            'url' => route('tips'),
        ]));
    }

    /**
     * The summary of the month that just ended is there to look back on.
     */
    public function summary(Vehicle $vehicle, CarbonImmutable $month): ?PushMessage
    {
        if (! $this->wants($vehicle, 'summary')) {
            return null;
        }

        return $this->inOwnersLocale($vehicle, fn () => $this->queue($vehicle, [
            'kind' => PushMessage::SUMMARY,
            'urgency' => 'info',
            'reference' => 'summary:'.$month->format('Y-m'),
            'title' => __('Your car in :month', ['month' => $month->locale(app()->getLocale())->isoFormat('MMMM')]),
            'body' => __('See what the month brought, and what it cost.')."\n".$this->name($vehicle),
            'icon' => 'ic:round-calendar-month',
            'url' => route('dashboard'),
        ]));
    }

    /**
     * Whether the owner wants this for the vehicle, and has a device to get it on.
     */
    private function wants(Vehicle $vehicle, string $kind): bool
    {
        return ($vehicle->pushPreferences()[$kind] ?? false) && $vehicle->user?->pushSubscriptions()->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function queue(Vehicle $vehicle, array $attributes): PushMessage
    {
        return PushMessage::query()->firstOrCreate(
            ['vehicle_id' => $vehicle->id, 'reference' => $attributes['reference']],
            [...$attributes, 'send_after' => $this->timing->sendAfter($vehicle, $attributes['urgency'])],
        );
    }

    private function name(Vehicle $vehicle): string
    {
        return trim($vehicle->full_name.' ('.$vehicle->license_plate.')');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOwnersLocale(Vehicle $vehicle, callable $callback): mixed
    {
        return $this->withLocale($vehicle->user->language ?: config('app.locale'), $callback);
    }
}
