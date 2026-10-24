<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Maintenance;
use App\Models\Refueling;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The odometer of a vehicle on any day, from its readings: those of the
 * refuelings, the maintenances and the purchase. Between two readings at the
 * pace in between; after the last one at the pace of the year before it,
 * which is an estimate.
 */
final class Odometer
{
    /** Days of readings at least, for a pace to go by. */
    private const PACE_DAYS = 30;

    /**
     * @param  Collection<int, array{date: CarbonImmutable, km: int}>  $readings
     */
    private function __construct(private readonly Collection $readings)
    {
        //
    }

    public static function of(Vehicle $vehicle): self
    {
        $vehicle->loadMissing(['refuelings', 'maintenances']);
        $highest = 0;

        // By day the highest reading, and none that is lower than one before it. The
        // vehicle was new at 0 km: what the pace before the purchase is told by.
        return new self(collect([
            ...$vehicle->refuelings->map(fn (Refueling $refueling) => [$refueling->date, (int) $refueling->mileage_end]),
            ...$vehicle->maintenances->map(fn (Maintenance $maintenance) => [$maintenance->date, (int) $maintenance->mileage]),
            [$vehicle->purchase_date, (int) $vehicle->mileage_start],
            [$vehicle->construction_date, 0, true],
        ])
            ->filter(fn (array $reading) => $reading[0] !== null && ($reading[1] > 0 || ($reading[2] ?? false)))
            ->map(fn (array $reading) => ['date' => CarbonImmutable::parse($reading[0])->startOfDay(), 'km' => $reading[1]])
            ->groupBy(fn (array $reading) => $reading['date']->toDateString())
            ->map(fn (Collection $day) => ['date' => $day->first()['date'], 'km' => (int) $day->max('km')])
            ->sortKeys()
            ->filter(function (array $reading) use (&$highest) {
                if ($reading['km'] < $highest) {
                    return false;
                }

                $highest = $reading['km'];

                return true;
            })
            ->values());
    }

    /**
     * The day of the first reading; null without readings.
     */
    public function firstDay(): ?CarbonImmutable
    {
        return $this->readings->first()['date'] ?? null;
    }

    /**
     * The odometer at the end of a day, and whether it is an estimate. Null
     * before the first reading, or after the last one without a pace to go by.
     *
     * @return array{0: float|null, 1: bool}
     */
    public function at(CarbonImmutable $day): array
    {
        $previous = $this->readings->last(fn (array $reading) => $reading['date']->lte($day));
        $next = $this->readings->first(fn (array $reading) => $reading['date']->gt($day));

        if ($previous === null) {
            return [null, false];
        }

        if ($next !== null) {
            $fraction = $previous['date']->diffInDays($day) / $previous['date']->diffInDays($next['date']);

            return [$previous['km'] + ($next['km'] - $previous['km']) * $fraction, false];
        }

        if ($previous['date']->eq($day)) {
            return [(float) $previous['km'], false];
        }

        $pace = $this->pace();

        return $pace === null ? [null, false] : [$previous['km'] + $pace * $previous['date']->diffInDays($day), true];
    }

    /**
     * The kilometers a day in the year before the last reading, or in the
     * time since the first one. Null with too few readings to tell.
     */
    public function pace(): ?float
    {
        $last = $this->readings->last();

        if ($last === null) {
            return null;
        }

        $since = $last['date']->subYear()->max($this->firstDay());
        $days = $since->diffInDays($last['date']);

        if ($days < self::PACE_DAYS) {
            return null;
        }

        [$then] = $this->at($since);

        return ($last['km'] - $then) / $days;
    }

    /**
     * The day the odometer reached, or is expected to reach, a mileage, and
     * whether it is an estimate. Null when it cannot be told.
     *
     * @return array{0: CarbonImmutable|null, 1: bool}
     */
    public function dayOf(int $km): array
    {
        $next = $this->readings->first(fn (array $reading) => $reading['km'] >= $km);

        if ($next !== null) {
            $previous = $this->readings->last(fn (array $reading) => $reading['date']->lt($next['date']));

            if ($previous === null || $next['km'] === $previous['km']) {
                return [$next['date'], false];
            }

            $fraction = ($km - $previous['km']) / ($next['km'] - $previous['km']);

            return [$previous['date']->addDays((int) round($previous['date']->diffInDays($next['date']) * $fraction)), false];
        }

        $last = $this->readings->last();
        $pace = $this->pace();

        if ($last === null || ! $pace) {
            return [null, false];
        }

        return [$last['date']->addDays((int) ceil(($km - $last['km']) / $pace)), true];
    }
}
