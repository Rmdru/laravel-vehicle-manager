<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\Maintenance;
use App\Models\Parking;
use App\Models\Product;
use App\Models\Reconditioning;
use App\Models\Refueling;
use App\Models\Toll;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * When a push notification of a vehicle is sent: something critical at once,
 * the rest at the moment the owner usually uses the app, the same day; never
 * in the quiet hours of the vehicle.
 */
class PushTiming
{
    /** The records the moment of the owner is derived from: when they entered them. */
    private const RECORDS = [Refueling::class, Maintenance::class, Parking::class, Toll::class, Reconditioning::class, Product::class];

    /**
     * When a message of the urgency that arises now is sent, in UTC.
     */
    public function sendAfter(Vehicle $vehicle, string $urgency, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $preferences = $vehicle->pushPreferences();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone($vehicle));
        $send = $now;

        // The rest waits for the moment, unless that already passed today.
        if ($urgency !== 'critical') {
            $moment = $now->setTimeFromTimeString($this->moment($vehicle));
            $send = $moment->greaterThan($now) ? $moment : $now;
        }

        return $this->afterQuietHours($send, $preferences['quiet_from'], $preferences['quiet_until'])->utc();
    }

    /**
     * The moment of the day the non-critical messages are sent at, as "HH:MM":
     * the one the owner chose, or else the hour they enter most records in.
     */
    public function moment(Vehicle $vehicle): string
    {
        return $vehicle->pushPreferences()['moment'] ?? $this->usualMoment($vehicle) ?? config('push.moment');
    }

    /**
     * The hour the owner enters most records of the vehicle in, in their own
     * time; null while there are too few to tell, or when it falls in the quiet hours.
     */
    public function usualMoment(Vehicle $vehicle): ?string
    {
        $since = now()->subMonths(config('push.moment_months'));
        $timezone = $this->timezone($vehicle);

        $times = collect(self::RECORDS)
            ->flatMap(fn (string $model) => $model::query()
                ->toBase()
                ->where('vehicle_id', $vehicle->id)
                ->where('created_at', '>=', $since)
                ->pluck('created_at'))
            ->filter();

        if ($times->count() < config('push.moment_records')) {
            return null;
        }

        $hour = $times
            ->map(fn ($time) => CarbonImmutable::parse($time, 'UTC')->setTimezone($timezone)->hour)
            ->countBy()
            // The most records, and of those the earliest hour.
            ->sortKeys()
            ->sortDesc()
            ->keys()
            ->first();
        $moment = sprintf('%02d:00', $hour);
        $preferences = $vehicle->pushPreferences();

        return $this->inQuietHours($moment, $preferences['quiet_from'], $preferences['quiet_until']) ? null : $moment;
    }

    /**
     * The time zone of the owner: the one of the country the vehicle is registered in.
     */
    public function timezone(Vehicle $vehicle): DateTimeZone
    {
        $country = (string) config("countries.{$vehicle->country_registration}.iso_code");
        $zones = $country === '' ? [] : DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);

        return new DateTimeZone($zones[0] ?? config('push.timezone'));
    }

    /**
     * The time, or the end of the quiet hours when it falls within them.
     */
    private function afterQuietHours(CarbonImmutable $time, ?string $from, ?string $until): CarbonImmutable
    {
        if (! $this->inQuietHours($time->format('H:i'), $from, $until)) {
            return $time;
        }

        $end = $time->setTimeFromTimeString((string) $until);

        return $end->greaterThan($time) ? $end : $end->addDay();
    }

    /**
     * Whether the time of day ("HH:MM") falls in the quiet hours, which can
     * run past midnight. Without both ends there are none.
     */
    public function inQuietHours(string $time, ?string $from, ?string $until): bool
    {
        if ($from === null || $until === null || $from === $until) {
            return false;
        }

        return $from < $until
            ? $time >= $from && $time < $until
            : $time >= $from || $time < $until;
    }
}
