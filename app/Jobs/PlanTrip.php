<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Trip;
use App\Services\TripPlanner\RoutingException;
use App\Services\TripPlanner\TripPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Plans a trip in the background: first the routes, which takes seconds, then
 * the stops along them, for which the places along the routes may have to be
 * fetched from OpenStreetMap first.
 */
class PlanTrip implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** The version of the trip this planning is for. */
    private int $version;

    /**
     * @param  bool  $fresh  The routes, the traffic and the fuel prices are asked anew, rather than taken from what was kept.
     */
    public function __construct(public Trip $trip, public bool $fresh = false)
    {
        $this->version = $trip->plan_version;
    }

    public function handle(TripPlanner $planner): void
    {
        // The version of the trip this planning is for: a trip that changed in the meantime is planned again anyway.
        $version = $this->version = $this->trip->plan_version;
        $this->progress(0.02, 'routes');
        // The traffic is told in the language of the driver.
        app()->setLocale((string) ($this->trip->user?->language ?: config('app.locale')));

        if ($this->fresh) {
            $planner->freshPrices();
        }

        try {
            $routes = $planner->routes($this->trip, $this->fresh);
            $this->progress(0.06, 'traffic');
            $traffic = $planner->traffic($this->trip, $routes, $this->fresh);
            $this->progress(0.1, 'places');

            if (! $this->save($version, [
                'status' => Trip::STATUS_ROUTED,
                'plan' => $planner->plan($this->trip, $routes, null, $traffic),
                // The route the driver chose, as long as there is one like it.
                'selected_route' => min((int) $this->trip->selected_route, count($routes) - 1),
                'error' => null,
            ])) {
                return;
            }

            // Fetching the places takes the most time: from 10% to 95%. It
            // stops in time for the job to finish: what is fetched is kept.
            $places = $planner->places(
                $routes,
                fn (float $done, string $stage, int $count, int $total) => $this->progress(0.1 + 0.85 * $done, $stage, $count, $total),
                microtime(true) + (int) config('trip_planner.overpass.budget'),
            );
            $this->progress(0.96, 'stops');

            $this->save($version, [
                'status' => Trip::STATUS_PLANNED,
                'plan' => $planner->plan($this->trip, $routes, $places, $traffic),
                'planned_at' => now(),
            ]);
        } catch (RoutingException $exception) {
            $this->save($version, ['status' => Trip::STATUS_FAILED, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
            $this->save($version, ['status' => Trip::STATUS_FAILED, 'error' => 'The trip could not be planned. Please try again later.']);
        }
    }

    /**
     * The job did not finish: it took too long, or the worker stopped.
     */
    public function failed(?Throwable $exception): void
    {
        $this->save($this->version, ['status' => Trip::STATUS_FAILED, 'error' => 'The trip could not be planned. Please try again later.']);
    }

    /**
     * Keeps how far the planning is with the trip, for the page to show.
     */
    private function progress(float $done, string $stage, ?int $count = null, ?int $total = null): void
    {
        Trip::whereKey($this->trip->getKey())
            ->where('plan_version', $this->version)
            ->update([
                'progress' => json_encode(array_filter([
                    'value' => round(min(1, $done), 3),
                    'stage' => $stage,
                    'done' => $count,
                    'total' => $total,
                ], fn ($value) => $value !== null)),
                // A planning that is no longer heard of has stopped.
                'updated_at' => now(),
            ]);
    }

    /**
     * Saves the planning, unless the trip changed in the meantime.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function save(int $version, array $attributes): bool
    {
        if (isset($attributes['plan'])) {
            $attributes['plan'] = json_encode($attributes['plan']);
        }

        // Once it is planned or failed, there is no progress any more.
        if (in_array($attributes['status'] ?? null, [Trip::STATUS_PLANNED, Trip::STATUS_FAILED], true)) {
            $attributes['progress'] = null;
        }

        return Trip::whereKey($this->trip->getKey())
            ->where('plan_version', $version)
            ->update([...$attributes, 'updated_at' => now()]) > 0;
    }
}
