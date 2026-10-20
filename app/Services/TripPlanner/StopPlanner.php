<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use Carbon\CarbonImmutable;
use Closure;

/**
 * Plans the stops along a route: a break with a coffee every 105 to 180
 * minutes of driving, a meal around breakfast, lunch and dinner time, a toilet
 * every 3 hours, fuel before the tank runs low, and a hotel after 10 hours. Each stop covers as many needs as it can, so that
 * there are as few stops as possible, as late as the rules allow. Fuel is
 * taken where it costs the least, the detour to a gas station included.
 */
class StopPlanner
{
    private const NEEDS = ['hotel', 'break', 'meal', 'toilet', 'fuel'];

    /** The feature a stop needs to cover a need. */
    private const FEATURE = ['hotel' => 'hotel', 'break' => 'coffee', 'meal' => 'meal', 'toilet' => 'toilet', 'fuel' => 'fuel'];

    /** How long before its deadline a need may be covered, in minutes, when it is the most urgent one. */
    private const WINDOW = ['toilet' => 60, 'fuel' => 120, 'hotel' => 90];

    /** Fuel is taken when it would otherwise run low within this many minutes. */
    private const FUEL_AHEAD = 180;

    /** Minutes before the toilet is due within which a stop meets it too: about the time to the next break. */
    private const TOILET_AHEAD = 105;

    /** What a euro saved on fuel is worth in the score of a place, against the needs it covers. */
    private const POINTS_PER_EURO = 2;

    /** A separate stop for fuel is worth it when it saves more than this, in euros. */
    private const SEPARATE_STOP_SAVING = 1.0;

    /** @var array<string, int|float> */
    private array $rules;

    private float $lastBreak = 0;

    /** The time of the next meal that is not eaten yet. */
    private ?CarbonImmutable $nextMeal = null;

    /** The time of all stops so far, in seconds: the clock runs on during them. */
    private float $stopSeconds = 0;

    private CarbonImmutable $departure;

    private float $lastToilet = 0;

    private float $fuel = 0;

    private float $fuelAt = 0;

    /** When the day of travel began, in seconds of driving, and the time of the stops since. */
    private float $dayStart = 0;

    private float $dayStops = 0;

    /** @var list<array<string, mixed>> */
    private array $stops = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  list<array<string, mixed>>  $candidates  The places a stop can be planned at, by distance along the route.
     * @param  list<array{along: float, stay: int, label: string}>  $waypoints  The destinations along the way.
     * @param  float  $fuelLevel  The part of the tank that is full at departure.
     * @param  (Closure(array<string, mixed>): ?float)|null  $priceOf  The price per liter at a place, if known.
     * @param  CarbonImmutable|null  $departure  When the trip begins, for the meals: the departure of the route by default.
     * @param  list<int>  $pinned  The places the driver chose to stop at, by their ids.
     */
    public function __construct(
        private Route $route,
        private array $candidates,
        private array $waypoints,
        private ?VehicleRange $range,
        private float $fuelLevel = 1.0,
        private ?Closure $priceOf = null,
        ?CarbonImmutable $departure = null,
        private array $pinned = [],
    ) {
        $this->rules = config('trip_planner.stops');
        $this->departure = ($departure ?? $route->departure)->setTimezone(config('trip_planner.timezone'));
    }

    /**
     * The stops in order, with the time of driving before each, and the needs
     * that could not be met.
     *
     * @return array{stops: list<array<string, mixed>>, warnings: list<string>}
     */
    public function plan(): array
    {
        $this->fuel = $this->range ? $this->range->tank * $this->fuelLevel : 0;
        $fixed = $this->fixedStops();
        // A meal just after leaving is eaten before.
        $this->nextMeal = $this->mealFrom($this->departure->addMinutes((int) $this->rules['meal_after_departure']));
        $position = 0.0;
        $end = $this->route->driveSeconds;

        // Each round plans a stop, or gives up on a need for lack of one: never more than this.
        $max = count($this->candidates) + count($fixed) + (int) ceil($end / 3600) * count(self::NEEDS) + 10;

        for ($round = 0; $round < $max; $round++) {
            $deadlines = $this->deadlines();
            asort($deadlines);
            $urgent = array_key_first($deadlines);
            $deadline = $deadlines[$urgent];

            // The next destination along the way, or place chosen to stop at, comes first.
            if ($fixed && $this->route->timeAt($fixed[0]['along']) <= $deadline) {
                $next = array_shift($fixed);
                $position = $next['fixed'] === 'waypoint' ? $this->stayAt($next) : $this->stopAt($next, 'pinned');

                continue;
            }

            if ($deadline >= $end) {
                break;
            }

            $candidate = $this->choose($urgent, $deadline, $position);

            if ($candidate === null) {
                $this->warnings[] = "no_{$urgent}";
                // Go on as if the need was met at its deadline, to plan the rest.
                $position = $this->route->distanceAfter($deadline);
                $urgent === 'meal' ? $this->nextMeal = $this->mealFrom($this->nextMeal->addMinute()) : $this->meet($urgent, $deadline, $position);

                continue;
            }

            $position = $this->stopAt($candidate, $urgent);
        }

        return ['stops' => $this->stops, 'warnings' => array_values(array_unique($this->warnings))];
    }

    /**
     * When each need has to be met at the latest, in seconds of driving.
     *
     * @return array<string, float>
     */
    private function deadlines(): array
    {
        $deadlines = [
            'break' => $this->lastBreak + $this->rules['break_before'] * 60,
            'meal' => $this->mealDeadline(),
            'toilet' => $this->lastToilet + $this->rules['toilet_every'] * 60,
            // The travel of a day, breaks included, ends at a hotel.
            'hotel' => $this->dayStart + $this->rules['hotel_every'] * 60 - $this->dayStops,
        ];

        if ($this->range) {
            $reserve = $this->range->tank * $this->range->reserve();
            $runsLowAt = $this->fuelAt + $this->range->metersOn(max(0, $this->fuel - $reserve));
            // Arriving before the tank runs low, there is no need to refuel.
            $deadlines['fuel'] = $runsLowAt >= $this->route->distance ? INF : $this->route->timeAt($runsLowAt);
        }

        return $deadlines;
    }

    /**
     * The best place for a stop for the most urgent need: within its window,
     * covering as many other needs as are due soon, as late as possible.
     *
     * @return array<string, mixed>|null
     */
    private function choose(string $urgent, float $deadline, float $position): ?array
    {
        $now = $this->route->timeAt($position);
        $windowStart = max($now, match ($urgent) {
            'break' => $this->lastBreak + $this->rules['break_after'] * 60,
            // Not sooner than a break may come, when there is time for that.
            'meal' => min(
                max(
                    $this->timeAtClock($this->nextMeal->subMinutes((int) $this->rules['meal_window'])),
                    $this->lastBreak + $this->rules['break_after'] * 60,
                ),
                $deadline,
            ),
            default => $deadline - self::WINDOW[$urgent] * 60,
        });

        $reachable = array_filter(
            $this->candidates,
            fn (array $candidate) => $candidate['along'] > $position + 500
                && $this->route->timeAt($candidate['along']) <= $deadline,
        );
        $suitable = array_filter($reachable, fn (array $candidate) => in_array($this->feature($urgent), $candidate['features'], true));
        $inWindow = array_filter($suitable, fn (array $candidate) => $this->route->timeAt($candidate['along']) >= $windowStart);

        // A gas station off the road only when fuel along it costs that much more.
        $suitable = array_filter($suitable, fn (array $candidate) => $this->detourAllowed($candidate, $reachable));
        $inWindow = array_filter($inWindow, fn (array $candidate) => $this->detourAllowed($candidate, $reachable));

        // Rather a stop too early than none at all; and a break at a rest area without coffee than none.
        $options = $inWindow ?: $suitable;

        if (! $options && $urgent === 'break') {
            $options = array_filter($reachable, fn (array $candidate) => in_array('parking', $candidate['features'], true));
            $options && $this->warnings[] = 'no_coffee';
        }

        if (! $options) {
            return null;
        }

        $best = null;
        $bestScore = null;

        foreach ($options as $candidate) {
            $time = $this->route->timeAt($candidate['along']);
            $covered = $this->covers($candidate, $time, $urgent);
            $deadlines = $this->deadlines();
            $score = 0;

            foreach ($covered as $need) {
                // A need that would call for another stop soon counts most.
                $score += ($deadlines[$need] ?? INF) < $time + $this->rules['break_after'] * 60 ? 10 : 3;
            }

            if ($urgent === 'meal') {
                // A meal as close to meal time as possible.
                $score += 5 * max(0, 1 - abs($time - $this->timeAtClock($this->nextMeal)) / ($this->rules['meal_window'] * 60));
            } else {
                // As late as possible.
                $score += $deadline > $windowStart ? 5 * ($time - $windowStart) / ($deadline - $windowStart) : 0;
            }

            // And close to the road.

            // Where fuel is taken, what it costs counts: the detour included.
            if (in_array('fuel', $covered, true) && ($cost = $this->fuelCost($candidate)) !== null) {
                $score -= self::POINTS_PER_EURO * $cost;
            } else {
                $score -= $candidate['offset'] / 200 + ($candidate['detour_seconds'] ?? 0) / 60;
            }

            if ($bestScore === null || $score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * The needs a stop at a place meets, at a time of driving: the urgent need
     * the stop is planned for, and the others that are (almost) due.
     *
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function covers(array $candidate, float $time, string $urgent): array
    {
        $offers = fn (string $need) => in_array($this->feature($need), $candidate['features'], true);
        $deadlines = $this->deadlines();
        // A need that is not due before the destination is not met along the way.
        $due = fn (string $need) => $urgent === $need || ($deadlines[$need] ?? INF) < $this->route->driveSeconds;
        $covered = [];

        if ($urgent === 'pinned') {
            $covered = $this->covers($candidate, $time, '');
            $fuelLow = $this->range && $this->fuel < $this->range->tank * $this->range->fillTo() * 0.9;

            return array_values(array_unique(array_filter([
                ...$covered,
                $offers('break') || in_array('parking', $candidate['features'], true) ? 'break' : null,
                $offers('toilet') && $this->deadlines()['toilet'] < $time + self::TOILET_AHEAD * 60 ? 'toilet' : null,
                $this->range && $offers('fuel') && $fuelLow ? 'fuel' : null,
            ])));
        }

        if ($urgent === 'hotel' && $offers('hotel')) {
            // A night resets the day: the other needs are met at the hotel too.
            return ['hotel', 'break', 'meal', 'toilet'];
        }

        // A meal when it is about meal time.
        if ($offers('meal') && $due('meal') && ($urgent === 'meal' || $this->isMealTime($this->clockAt($time)))) {
            $covered[] = 'meal';
        }

        if ($covered || ($offers('break') && $due('break') && ($urgent === 'break' || $time - $this->lastBreak >= $this->rules['break_after'] * 60))) {
            $covered[] = 'break';
        }

        // A toilet only when it is needed: else soon a stop of its own.
        if ($offers('toilet') && ($urgent === 'toilet' || $deadlines['toilet'] < $time + self::TOILET_AHEAD * 60)) {
            $covered[] = 'toilet';
        }

        if ($this->range && $offers('fuel') && $due('fuel') && ($urgent === 'fuel' || $deadlines['fuel'] < $time + self::FUEL_AHEAD * 60)) {
            $covered[] = 'fuel';
        }

        return $covered;
    }

    /**
     * Plans a stop at a place, and returns the new position along the route.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function stopAt(array $candidate, string $urgent): float
    {
        $time = $this->route->timeAt($candidate['along']);
        $needs = $this->covers($candidate, $time, $urgent);

        // Fuel that is cheaper further on, worth a stop of its own, is taken there.
        if (! in_array($urgent, ['fuel', 'pinned'], true) && in_array('fuel', $needs, true) && $this->cheaperFurtherOn($candidate)) {
            $needs = array_values(array_diff($needs, ['fuel']));
        }

        // At a rest area without coffee, the break is a rest.
        if ($urgent === 'break' && ! in_array('break', $needs, true)) {
            $needs[] = 'break';
        }

        $this->drive($candidate['along']);
        $liters = in_array('fuel', $needs, true) && $this->range ? round(max(0, $this->range->tank * $this->range->fillTo() - $this->fuel), 1) : null;

        foreach ($needs as $need) {
            $this->meet($need, $time, $candidate['along']);
        }

        $minutes = $this->minutes($needs, $liters, $candidate['power'] ?? null);
        $this->dayStops += $minutes * 60 + ($candidate['detour_seconds'] ?? 0);
        $this->stopSeconds += $minutes * 60 + ($candidate['detour_seconds'] ?? 0);

        if (in_array('hotel', $needs, true)) {
            $this->dayStart = $time;
            $this->dayStops = 0;
            // The meals during the night are eaten at the hotel.
            $this->nextMeal = $this->mealFrom($this->clockAt($time));
        }

        $this->stops[] = [
            ...$candidate,
            'type' => 'stop',
            'needs' => array_values(array_intersect(self::NEEDS, $needs)),
            'drive_seconds' => round($time),
            'minutes' => $minutes,
            'fuel_liters' => $liters,
        ];

        return $candidate['along'];
    }

    /**
     * Plans the stay at a destination along the way, and returns the new position.
     *
     * @param  array{along: float, stay: int, label: string}  $waypoint
     */
    private function stayAt(array $waypoint): float
    {
        $time = $this->route->timeAt($waypoint['along']);
        $this->drive($waypoint['along']);
        $needs = [];

        if ($waypoint['stay'] >= $this->rules['stay_counts_as_break']) {
            $needs = ['break', 'toilet'];
        }

        // A meal there when it is meal time during the stay.
        $arrival = $this->clockAt($time);
        $leaving = $arrival->addMinutes($waypoint['stay']);
        $window = (int) $this->rules['meal_window'];

        if ($waypoint['stay'] >= $this->rules['stay_counts_as_meal'] && $this->nextMeal
            && $this->nextMeal->subMinutes($window)->lte($leaving) && $this->nextMeal->addMinutes($window)->gte($arrival)) {
            $needs[] = 'meal';
        }

        if ($waypoint['stay'] >= $this->rules['stay_counts_as_night']) {
            $needs[] = 'hotel';
        }

        foreach ($needs as $need) {
            $this->meet($need, $time, $waypoint['along']);
        }

        if (! in_array('hotel', $needs, true)) {
            $this->dayStops += $waypoint['stay'] * 60;
        }

        $this->stopSeconds += $waypoint['stay'] * 60;

        // The meals during a long stay are eaten there.
        if (in_array('meal', $needs, true) || in_array('hotel', $needs, true)) {
            $this->nextMeal = $this->mealFrom($leaving);
        }

        $this->stops[] = [
            'type' => 'waypoint',
            'name' => $waypoint['label'],
            'along' => $waypoint['along'],
            'needs' => $needs,
            'drive_seconds' => round($time),
            'minutes' => $waypoint['stay'],
        ];

        return $waypoint['along'];
    }

    /**
     * What a full tank costs at a place, in euros, with the detour to it: for
     * the same amount at every place, so that places can be compared.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function fuelCost(array $candidate): ?float
    {
        $price = $this->priceOf && $this->range ? ($this->priceOf)($candidate) : null;

        if ($price === null) {
            return null;
        }

        $liters = $this->range->tank * (1 - $this->range->reserve()) + $this->range->litersFor((float) ($candidate['detour_meters'] ?? 0));

        // The time of a detour that is allowed is not counted: it is worth it.
        return $liters * $price;
    }

    /**
     * Whether a detour to a place off the road is allowed: only to a gas
     * station where fuel costs at least so much less than at the cheapest gas
     * station along the road among the places that can be reached. Without
     * any along the road, a detour is the only way.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<array-key, array<string, mixed>>  $reachable
     */
    private function detourAllowed(array $candidate, array $reachable): bool
    {
        if (($candidate['detour_seconds'] ?? 0) <= 0 || ! in_array($this->feature('fuel'), $candidate['features'], true)) {
            return true;
        }

        // Charging off the road only when there is no charger along it.
        if ($this->range?->electric) {
            return ! array_filter($reachable, fn (array $other) => ($other['detour_seconds'] ?? 0) <= 0 && in_array('charge', $other['features'], true));
        }

        $price = $this->priceOf ? ($this->priceOf)($candidate) : null;

        if ($price === null) {
            return false;
        }

        $alongTheRoad = null;

        foreach ($reachable as $other) {
            if (($other['detour_seconds'] ?? 0) <= 0 && in_array($this->feature('fuel'), $other['features'], true)) {
                $otherPrice = ($this->priceOf)($other);

                if ($otherPrice !== null && ($alongTheRoad === null || $otherPrice < $alongTheRoad)) {
                    $alongTheRoad = $otherPrice;
                }
            }
        }

        return $alongTheRoad === null
            || $alongTheRoad - $price >= (float) config('trip_planner.prices.detour_min_saving') - 0.0001;
    }

    /**
     * Whether fuel costs so much less at a gas station between a place and
     * where the tank runs low, that a stop of its own there is worth it.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function cheaperFurtherOn(array $candidate): bool
    {
        $here = $this->fuelCost($candidate);
        $deadline = $this->deadlines()['fuel'] ?? INF;

        if ($here === null) {
            return false;
        }

        $stopCost = (float) $this->rules['fuel_minutes'] / 60 * (float) config('trip_planner.prices.time_value');
        $further = array_filter(
            $this->candidates,
            fn (array $other) => $other['along'] > $candidate['along'] + 500
                && $this->route->timeAt($other['along']) <= $deadline,
        );

        foreach ($further as $other) {
            if (! in_array($this->feature('fuel'), $other['features'], true) || ! $this->detourAllowed($other, [$candidate, ...$further])) {
                continue;
            }

            $there = $this->fuelCost($other);

            if ($there !== null && $here - $there - $stopCost > self::SEPARATE_STOP_SAVING) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uses the fuel for the distance to a position along the route.
     */
    private function drive(float $along): void
    {
        if ($this->range) {
            $this->fuel = max(0, $this->fuel - $this->range->litersFor($along - $this->fuelAt));
            $this->fuelAt = $along;
        }
    }

    private function meet(string $need, float $time, float $along): void
    {
        if ($need === 'fuel' && $this->range) {
            $this->drive($along);
            $this->fuel = max($this->fuel, $this->range->tank * $this->range->fillTo());
        }

        if ($need === 'break' || $need === 'meal') {
            $this->lastBreak = $time;
        }

        // The next meal is the first one after this one.
        if ($need === 'meal' && $this->nextMeal) {
            $this->nextMeal = $this->mealFrom($this->clockAt($time)->addMinutes((int) $this->rules['meal_minutes'] + (int) $this->rules['meal_window']));
        }

        if ($need === 'toilet') {
            $this->lastToilet = $time;
        }

        // A new day of travel begins.
        if ($need === 'hotel') {
            $this->dayStart = $time;
            $this->dayStops = 0;
        }
    }

    /**
     * The feature a stop needs to meet a need: charging rather than fuel for
     * an electric vehicle.
     */
    private function feature(string $need): string
    {
        return $need === 'fuel' && $this->range?->electric ? 'charge' : self::FEATURE[$need];
    }

    /**
     * The places the driver chose to stop at, with the destinations along
     * the way, in the order they are reached.
     *
     * @return list<array<string, mixed>>
     */
    private function fixedStops(): array
    {
        $fixed = [
            ...array_map(fn (array $waypoint) => ['fixed' => 'waypoint', ...$waypoint], $this->waypoints),
            ...array_map(fn (array $candidate) => ['fixed' => 'pinned', ...$candidate], array_values(array_filter(
                $this->candidates,
                fn (array $candidate) => in_array($candidate['poi_id'], $this->pinned, true),
            ))),
        ];
        usort($fixed, fn (array $a, array $b) => $a['along'] <=> $b['along']);

        return $fixed;
    }

    /**
     * The clock time at a time of driving: the stops so far take time too.
     */
    private function clockAt(float $time): CarbonImmutable
    {
        return $this->departure->addSeconds((int) round($time + $this->stopSeconds));
    }

    /**
     * The time of driving at a clock time, if there are no more stops before.
     */
    private function timeAtClock(CarbonImmutable $clock): float
    {
        return $clock->getTimestamp() - $this->departure->getTimestamp() - $this->stopSeconds;
    }

    /**
     * The first meal time from a moment on: breakfast, lunch or dinner.
     */
    private function mealFrom(CarbonImmutable $moment): ?CarbonImmutable
    {
        $moment = $moment->setTimezone(config('trip_planner.timezone'));

        for ($day = 0; $day < 3; $day++) {
            foreach ((array) $this->rules['meals'] as $meal) {
                [$hour, $minute] = array_map('intval', explode(':', (string) $meal));
                $time = $moment->startOfDay()->addDays($day)->setTime($hour, $minute);

                if ($time->gte($moment)) {
                    return $time;
                }
            }
        }

        return null;
    }

    /**
     * When the next meal has to be eaten at the latest, in seconds of driving:
     * some time after meal time, and before the destination. When the
     * destination is reached by about meal time, it is eaten there.
     */
    private function mealDeadline(): float
    {
        if ($this->nextMeal === null) {
            return INF;
        }

        $window = (int) $this->rules['meal_window'];

        if ($this->timeAtClock($this->nextMeal->addMinutes(intdiv($window, 2))) >= $this->route->driveSeconds) {
            return INF;
        }

        // Without another break before the destination, a meal soon after
        // meal time is eaten there, rather than at a stop of its own.
        if ($this->lastBreak + $this->rules['break_after'] * 60 >= $this->route->driveSeconds
            && $this->timeAtClock($this->nextMeal->addMinutes(2 * $window)) >= $this->route->driveSeconds) {
            return INF;
        }

        return min($this->timeAtClock($this->nextMeal->addMinutes($window)), $this->route->driveSeconds - 1);
    }

    /**
     * Whether it is about the time of the next meal at a clock time.
     */
    private function isMealTime(CarbonImmutable $clock): bool
    {
        return $this->nextMeal !== null
            && abs($clock->getTimestamp() - $this->nextMeal->getTimestamp()) <= $this->rules['meal_window'] * 60;
    }

    /**
     * How long a stop for needs takes: a meal includes a break, and the toilet
     * is visited during either.
     *
     * @param  list<string>  $needs
     */
    private function minutes(array $needs, ?float $energy = null, ?float $power = null): int
    {
        if ($this->range?->electric && in_array('fuel', $needs, true)) {
            $charging = $this->range->chargeMinutes((float) $energy, $power) + (int) config('trip_planner.ev.overhead_minutes');
            $needs = array_values(array_diff($needs, ['fuel']));

            // The break, the meal or the toilet is done while charging.
            return max($charging, $this->minutes($needs));
        }

        $minutes = match (true) {
            in_array('hotel', $needs, true) => $this->rules['night_minutes'],
            in_array('meal', $needs, true) => $this->rules['meal_minutes'],
            in_array('break', $needs, true) => $this->rules['break_minutes'],
            in_array('toilet', $needs, true) && ! in_array('fuel', $needs, true) => $this->rules['toilet_minutes'],
            default => 0,
        };

        return (int) $minutes + (in_array('fuel', $needs, true) ? (int) $this->rules['fuel_minutes'] : 0);
    }
}
