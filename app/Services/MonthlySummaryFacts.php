<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Refueling;
use App\Models\Vehicle;
use App\Models\VehicleSummary;
use App\Support\Odometer;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The facts the summary of a month of a vehicle is based on, calculated
 * rather than left to the AI model: the distance driven, the costs against
 * the average and what is due soon.
 *
 * Early in a month (see review_until_day) it is the previous month, else the
 * current month so far. The averages are of the twelve months before it; for
 * the month so far they are scaled to the days that passed.
 */
class MonthlySummaryFacts
{
    /** Costs of using the vehicle, that go with the distance driven. */
    private const RUNNING = ['Fuel', 'Parking', 'Toll', 'Transport trains & ferry services'];

    /** Costs that are the same every month. */
    private const FIXED = ['Insurance', 'Road tax'];

    /** Months the averages are of. */
    private const AVERAGE_MONTHS = 12;

    /**
     * @return array<string, mixed>
     */
    public function for(Vehicle $vehicle): array
    {
        $vehicle->loadMissing(['maintenances', 'insurances', 'taxes', 'refuelings']);

        $period = $this->period();
        $start = CarbonImmutable::parse($period['start']);
        $end = CarbonImmutable::parse($period['end']);
        $share = $period['days'] / $period['days_in_month'];

        return [
            'today' => today()->toDateString(),
            'period' => $period,
            'distance' => $this->distance($vehicle, $start, $end, $share),
            'costs' => $this->costs($vehicle, $start, $end, $period['kind'] === VehicleSummary::MONTH_TO_DATE),
            'consumption' => $this->consumption($vehicle, $start, $end),
            'refuelings' => $vehicle->refuelings->filter(fn (Refueling $refueling) => $refueling->date->between($start, $end))->count(),
            'upcoming' => $this->upcoming($vehicle),
        ];
    }

    /**
     * What tells whether the facts changed, apart from the day they are of.
     *
     * @param  array<string, mixed>  $facts
     */
    public function fingerprint(array $facts): string
    {
        unset($facts['today']);

        return hash('sha256', (string) json_encode($facts));
    }

    /**
     * @return array{kind: string, month: string, start: string, end: string, days: int, days_in_month: int}
     */
    private function period(): array
    {
        $today = CarbonImmutable::today();
        $review = $today->day <= config('monthly_summary.review_until_day');
        $month = $review ? $today->subMonthNoOverflow()->startOfMonth() : $today->startOfMonth();
        $end = $review ? $month->endOfMonth()->startOfDay() : $today;

        return [
            'kind' => $review ? VehicleSummary::PREVIOUS_MONTH : VehicleSummary::MONTH_TO_DATE,
            'month' => $month->format('Y-m'),
            'start' => $month->toDateString(),
            'end' => $end->toDateString(),
            'days' => (int) $month->diffInDays($end) + 1,
            'days_in_month' => $month->daysInMonth,
        ];
    }

    /**
     * The distance driven, from the odometer readings (see Odometer).
     *
     * @return array<string, mixed>|null
     */
    private function distance(Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end, float $share): ?array
    {
        $odometer = Odometer::of($vehicle);
        $before = $start->subDay();
        [$from] = $odometer->at($before);
        [$to, $estimated] = $odometer->at($end);

        if ($from === null || $to === null) {
            return null;
        }

        // The average month of the year before, or of the time since the first reading.
        $since = $before->subMonthsNoOverflow(self::AVERAGE_MONTHS)->max($odometer->firstDay());
        $months = $since->diffInDays($before) / 30.44;
        [$then] = $odometer->at($since);
        $average = $months >= 1 && $then !== null ? ($from - $then) / $months : null;

        return array_filter([
            'km' => (int) round($to - $from),
            'estimated' => $estimated,
            'average_month_km' => $average === null ? null : (int) round($average),
            'expected_km' => $average === null ? null : (int) round($average * $share),
        ], fn ($value) => $value !== null);
    }

    /**
     * The costs of the period, split up: the running costs, the fixed ones,
     * and the incidental ones by type. The fuel is compared by its costs per
     * kilometer: what the refuelings of a month cost depends on when they
     * happen to fall.
     *
     * @param  bool  $soFar  Whether the period is the month so far, rather than a full month.
     * @return array<string, mixed>
     */
    private function costs(Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end, bool $soFar): array
    {
        $byType = collect($vehicle->calculateMonthlyCosts($start->toDateString(), $end->endOfDay()->toDateTimeString())['monthlyCosts'])
            ->reduce(function (array $sums, array $month) {
                foreach ($month as $type => $amount) {
                    $sums[$type] = ($sums[$type] ?? 0) + (float) $amount;
                }

                return $sums;
            }, []);

        $average = $this->averageCosts($vehicle, $start);
        $sum = fn (?array $amounts, array $types) => round(collect($amounts ?? [])->only($types)->sum(), 2);
        $incidental = collect($byType)->except([...self::RUNNING, ...self::FIXED])->filter(fn ($amount) => $amount > 0)->map(fn ($amount) => round($amount, 2));

        return array_filter([
            'total_eur' => round(array_sum($byType), 2),
            'running_eur' => $sum($byType, self::RUNNING),
            'fixed_eur' => $sum($byType, self::FIXED),
            'incidental_eur' => $incidental->sortDesc()->all(),
            'average_month_total_eur' => $average === null ? null : round(array_sum($average), 2),
            'average_to_date_total_eur' => $soFar ? $this->averageCostsToDay($vehicle, $start, $end->day) : null,
            'fuel_per_km_eur' => $this->average($vehicle, 'costs_per_kilometer', $start, $end, 3),
            'average_fuel_per_km_eur' => $this->average($vehicle, 'costs_per_kilometer', $start->subYear(), $start->subDay(), 3),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * The average of a figure of the refuelings between two days, of those
     * that have it. Null when none has.
     */
    private function average(Vehicle $vehicle, string $attribute, CarbonImmutable $from, CarbonImmutable $until, int $precision = 2): ?float
    {
        $average = $vehicle->refuelings
            ->filter(fn (Refueling $refueling) => $refueling->date->between($from, $until) && (float) $refueling->{$attribute} > 0)
            ->avg(fn (Refueling $refueling) => (float) $refueling->{$attribute});

        return $average ? round((float) $average, $precision) : null;
    }

    /**
     * The average total costs up to the same day of the month, of the twelve
     * months before the period, or of the months since the first cost: what
     * part of a month compares with. Null without such a month.
     */
    private function averageCostsToDay(Vehicle $vehicle, CarbonImmutable $start, int $day): ?float
    {
        $first = CarbonImmutable::parse((new VehicleCostsService($vehicle))->getMonths($vehicle->id)['startDate'])->startOfMonth();
        $totals = [];

        for ($months = 1; $months <= self::AVERAGE_MONTHS; $months++) {
            $month = $start->subMonthsNoOverflow($months);

            if ($month->lt($first)) {
                break;
            }

            $until = $month->addDays(min($day, $month->daysInMonth) - 1)->endOfDay();
            $costs = $vehicle->calculateMonthlyCosts($month->toDateString(), $until->toDateTimeString())['monthlyCosts'];
            $totals[] = array_sum(array_map('array_sum', $costs));
        }

        return $totals === [] ? null : round(array_sum($totals) / count($totals), 2);
    }

    /**
     * The average costs of a month by type, of the twelve months before the
     * period, or of the months since the first cost. Null without a full month.
     *
     * @return array<string, float>|null
     */
    private function averageCosts(Vehicle $vehicle, CarbonImmutable $start): ?array
    {
        $first = (new VehicleCostsService($vehicle))->getMonths($vehicle->id)['startDate'];
        $from = $start->subMonthsNoOverflow(self::AVERAGE_MONTHS)->max(CarbonImmutable::parse($first)->startOfMonth());

        if ($from->gte($start)) {
            return null;
        }

        return $vehicle->calculateAverageMonthlyCostsByType($from->toDateString(), $start->subDay()->endOfDay()->toDateTimeString());
    }

    /**
     * The average consumption of the refuelings of the period, against that of
     * the year before. Null without refuelings in the period.
     *
     * @return array<string, mixed>|null
     */
    private function consumption(Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $current = $this->average($vehicle, 'fuel_consumption', $start, $end);

        if ($current === null) {
            return null;
        }

        return array_filter([
            'value' => $current,
            'average' => $this->average($vehicle, 'fuel_consumption', $start->subYear(), $start->subDay()),
            'unit' => trans('powertrains')[$vehicle->powertrain]['consumption_unit'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The deadlines in the coming weeks, the first first. Overdue ones are
     * left out: the dashboard warns about those under "Needs attention".
     *
     * @return list<array{kind: string, date: string, country?: string, component?: string, types?: list<string>}>
     */
    private function upcoming(Vehicle $vehicle): array
    {
        $until = today()->addDays(config('monthly_summary.upcoming_within'));
        $current = fn (Collection $records) => $records->where('start_date', '<=', today())->sortByDesc('start_date')->first();
        $valid = fn ($query) => $query->whereNotNull('end_date')->whereDate('end_date', '>=', today())->get();

        return collect([
            ['kind' => 'mot', 'date' => $vehicle->apkExpiryDate()],
            // Every maintenance interval, such as the inspection service and the oil service.
            ...array_map(fn (array $next) => ['kind' => 'maintenance', 'date' => $next['due'], 'types' => $next['types']], $vehicle->nextMaintenances()),
            ['kind' => 'insurance', 'date' => $current($vehicle->insurances)?->end_date],
            ['kind' => 'road_tax', 'date' => $current($vehicle->taxes)?->end_date],
            ...$valid($vehicle->vignettes())->map(fn ($vignette) => ['kind' => 'vignette', 'date' => $vignette->end_date, 'country' => $vignette->country]),
            ...$valid($vehicle->environmentalStickers())->map(fn ($sticker) => ['kind' => 'environmental_sticker', 'date' => $sticker->end_date, 'country' => $sticker->country]),
            ...collect(app(MaintenancePlanService::class)->plan($vehicle))
                ->map(fn (array $item) => ['kind' => 'component', 'date' => $item['due_date'], 'component' => $item['key']]),
        ])
            ->filter(fn (array $item) => $item['date'] !== null && Carbon::parse($item['date'])->between(today(), $until))
            ->map(fn (array $item) => [...$item, 'date' => Carbon::parse($item['date'])->toDateString()])
            ->sortBy('date')
            ->values()
            ->all();
    }
}
