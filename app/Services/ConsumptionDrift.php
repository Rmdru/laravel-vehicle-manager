<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VehicleStatus;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Support\StatusNotification;
use Illuminate\Support\Collection;

/**
 * Whether the vehicle uses clearly more than it used to: a sign of a tire
 * that is soft, brakes that drag, a sensor or a filter, before it gets worse.
 * Compared with the same time of year a year before when the refuelings tell,
 * as winter by itself makes a vehicle use more; else with the refuelings
 * before.
 */
class ConsumptionDrift
{
    /** Refuelings the recent consumption is the average of. */
    private const RECENT = 5;

    /** Refuelings of the recent consumption at least. */
    private const RECENT_AT_LEAST = 3;

    /** Refuelings before those the consumption is compared with, at most and at least. */
    private const BEFORE = 15;

    private const BEFORE_AT_LEAST = 5;

    /** Days around the same dates a year before that count as the same time of year. */
    private const SEASON_DAYS = 30;

    /** How much more, as a share, makes a notification. */
    private const THRESHOLD = 0.10;

    /**
     * The recent consumption against the usual one, or null when there are
     * too few refuelings to tell.
     *
     * @return array{recent: float, usual: float, increase: float, unit: string, same_season: bool}|null
     */
    public function of(Vehicle $vehicle): ?array
    {
        $refuelings = $vehicle->refuelings
            ->filter(fn (Refueling $refueling) => (float) $refueling->fuel_consumption > 0)
            ->sortByDesc(fn (Refueling $refueling) => $refueling->date->toDateString().' '.$refueling->created_at)
            ->values();
        $recent = $refuelings->take(self::RECENT);

        if ($recent->count() < self::RECENT_AT_LEAST) {
            return null;
        }

        $from = $recent->last()->date->copy()->subYear()->subDays(self::SEASON_DAYS);
        $until = $recent->first()->date->copy()->subYear()->addDays(self::SEASON_DAYS);
        $season = $refuelings->filter(fn (Refueling $refueling) => $refueling->date->between($from, $until));
        $sameSeason = $season->count() >= self::RECENT_AT_LEAST;
        $before = $sameSeason ? $season : $refuelings->slice(self::RECENT, self::BEFORE);

        if (! $sameSeason && $before->count() < self::BEFORE_AT_LEAST) {
            return null;
        }

        $recentAverage = $this->average($recent);
        $usual = $this->average($before);

        return [
            'recent' => round($recentAverage, 1),
            'usual' => round($usual, 1),
            'increase' => $recentAverage / $usual - 1,
            'unit' => trans('powertrains')[$vehicle->powertrain]['consumption_unit'] ?? '',
            'same_season' => $sameSeason,
        ];
    }

    /**
     * A notification when the vehicle uses clearly more than usual, with the
     * increase in steps of five percent: it comes back when it grows.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        if ($vehicle->status !== VehicleStatus::Drivable->value) {
            return [];
        }

        $drift = $this->of($vehicle);

        if ($drift === null || $drift['increase'] < self::THRESHOLD) {
            return [];
        }

        $types = StatusNotification::types();

        return [[
            ...$types['warning'],
            'key' => 'consumption_drift',
            'type' => 'warning',
            'text' => __('The consumption is :percent% higher than usual (:recent against :usual :unit): check the tire pressure, and have the brakes and the engine looked at when it stays.', [
                'percent' => (int) (floor($drift['increase'] * 20) * 5),
                'recent' => number_format($drift['recent'], 1, ',', '.'),
                'usual' => number_format($drift['usual'], 1, ',', '.'),
                'unit' => $drift['unit'],
            ]),
            'icon' => 'mdi:chart-line',
            'typeIcon' => $types['warning']['icon'],
            'hasModal' => false,
            'data' => [],
            'url' => route('refuelings.index'),
            'urlLabel' => __('Refuelings'),
        ]];
    }

    /**
     * @param  Collection<int, Refueling>  $refuelings
     */
    private function average(Collection $refuelings): float
    {
        return (float) $refuelings->avg(fn (Refueling $refueling) => (float) $refueling->fuel_consumption);
    }
}
