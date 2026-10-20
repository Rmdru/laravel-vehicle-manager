<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Trip;
use App\Services\TripPlanner\RoutingException;
use App\Services\TripPlanner\TripPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(public Trip $trip) {}

    public function handle(TripPlanner $planner): void
    {
        // The version of the trip this planning is for: a trip that changed in the meantime is planned again anyway.
        $version = $this->trip->plan_version;
        $this->progress(0.02);
        // The traffic is told in the language of the driver.
        app()->setLocale((string) ($this->trip->user?->language ?: config('app.locale')));

        try {
            $routes = $planner->routes($this->trip);
            $traffic = $planner->traffic($this->trip, $routes);
            $this->progress(0.1);

            if (! $this->save($version, [
                'status' => Trip::STATUS_ROUTED,
                'plan' => $planner->plan($this->trip, $routes, null, $traffic),
                // The route the driver chose, as long as there is one like it.
                'selected_route' => min((int) $this->trip->selected_route, count($routes) - 1),
                'error' => null,
            ])) {
                return;
            }

            // Fetching the places takes the most time: from 10% to 95%.
            $places = $planner->places($routes, fn (float $done) => $this->progress(0.1 + 0.85 * $done));

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
        } finally {
            Cache::forget(self::progressKey($this->trip));
        }
    }

    /**
     * Where the progress of the planning of a trip is kept, for the page to show.
     */
    public static function progressKey(Trip $trip): string
    {
        return "trip-planner:progress:{$trip->getKey()}";
    }

    private function progress(float $done): void
    {
        Cache::put(self::progressKey($this->trip), round(min(1, $done), 3), now()->addHour());
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

        return Trip::whereKey($this->trip->getKey())
            ->where('plan_version', $version)
            ->update([...$attributes, 'updated_at' => now()]) > 0;
    }
}
