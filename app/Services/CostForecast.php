<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finding;
use App\Models\Insurance;
use App\Models\Tax;
use App\Models\Vehicle;
use App\Support\Odometer;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * What the vehicle is expected to cost in the coming twelve months, and so
 * what to put aside a month; what it cost per kilometer since it was bought;
 * and whether the repairs ahead are still worth it against what it is worth.
 */
class CostForecast
{
    /** Months ahead. */
    public const MONTHS = 12;

    /** Years of maintenance the unforeseen costs are told by, at most. */
    private const HISTORY_YEARS = 3;

    /**
     * The costs of the running kinds: what driving and keeping the vehicle
     * takes besides the fuel, the fixed costs and the maintenance.
     */
    private const RUNNING = ['Reconditioning', 'Parking', 'Toll', 'Vignette', 'Environmental sticker', 'Transport trains & ferry services', 'Product', 'Service'];

    /** Share of the value from which the repairs ahead are a reason to think of replacing it. */
    private const REPLACE_SHARE = 0.5;

    /** Share of the value from which they are worth weighing. */
    private const WEIGH_SHARE = 0.25;

    public function __construct(
        private readonly MaintenancePlanService $plan,
        private readonly PriceHistory $prices,
        private readonly FindingService $findings,
    ) {
        //
    }

    /**
     * The costs of the coming twelve months by item, in groups, with the
     * total and what to put aside a month. An item whose amount cannot be
     * told is listed without one.
     *
     * @return array<string, mixed>
     */
    public function forecast(Vehicle $vehicle): array
    {
        $vehicle->loadMissing(['maintenances', 'refuelings', 'insurances', 'taxes']);
        $averages = rescue(fn () => $vehicle->calculateAverageMonthlyCostsByType(today()->subYear()->toDateString(), today()->toDateString()), [], false);
        $item = fn (string $label, string $icon, ?float $amount, string $basis, ?string $when = null) => [
            'label' => $label,
            'icon' => $icon,
            'amount' => $amount === null ? null : round($amount, 2),
            'basis' => $basis,
            'when' => $when,
        ];

        $insurance = $vehicle->insurances->first(fn (Insurance $insurance) => $insurance->start_date?->lte(today()) && ($insurance->end_date === null || $insurance->end_date->gte(today())));
        $tax = $vehicle->taxes->first(fn (Tax $tax) => $tax->start_date?->lte(today()) && ($tax->end_date === null || $tax->end_date->gte(today())));

        $fixed = array_values(array_filter([
            $insurance ? $item(__('Insurance'), 'mdi:shield-car', (float) $insurance->price * self::MONTHS, __('Your current premium')) : null,
            $tax ? $item(__('Road tax'), 'mdi:highway', (float) $tax->price * self::MONTHS, __('What you pay now')) : null,
        ]));

        $running = array_values(array_filter([
            ($averages['Fuel'] ?? 0) > 0 ? $item(__('Fuel'), 'ic:round-local-gas-station', $averages['Fuel'] * self::MONTHS, __('As in the past year')) : null,
            ($other = collect(self::RUNNING)->sum(fn (string $type) => $averages[$type] ?? 0)) > 0
                ? $item(__('Other running costs'), 'mdi:cash-multiple', $other * self::MONTHS, __('Washing, parking, toll and the like, as in the past year'))
                : null,
        ]));

        $maintenance = $this->maintenance($vehicle, $item);
        $planned = collect($maintenance)->sum('amount');
        $unforeseen = $this->unforeseen($vehicle, $planned);

        if ($unforeseen !== null) {
            $maintenance[] = [...$item(__('Unforeseen repairs'), 'mdi:hammer-wrench', $unforeseen, __('What maintenance cost in a year before, more than what is planned now')), 'unforeseen' => true];
        }

        $groups = [
            ['key' => 'fixed', 'label' => __('Fixed costs'), 'items' => $fixed],
            ['key' => 'running', 'label' => __('Driving'), 'items' => $running],
            ['key' => 'maintenance', 'label' => __('Maintenance & repairs'), 'items' => $maintenance],
        ];
        $total = collect($groups)->flatMap(fn (array $group) => $group['items'])->sum('amount');
        $comparison = $this->comparison($vehicle);

        return [
            'groups' => array_values(array_filter($groups, fn (array $group) => $group['items'] !== [])),
            'total' => round($total, 2),
            'perMonth' => round($total / self::MONTHS, 2),
            'unknown' => collect($groups)->flatMap(fn (array $group) => $group['items'])->whereNull('amount')->count(),
            // Not paid, but lost: shown apart from what to put aside.
            'depreciation' => isset($comparison['depreciation_per_year_eur']) ? (float) $comparison['depreciation_per_year_eur'] : null,
        ];
    }

    /**
     * The maintenance planned in the coming twelve months: the components of
     * the plan that fall due, the MOT, and the findings with what they are
     * expected to cost. A component of a finding is counted with the finding.
     *
     * @param  callable(string, string, float|null, string, string|null): array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function maintenance(Vehicle $vehicle, callable $item): array
    {
        $until = today()->addMonths(self::MONTHS);
        $open = $this->findings->open($vehicle);
        $covered = $open->filter(fn (Finding $finding) => $finding->estimated_cost)->flatMap(fn (Finding $finding) => (array) $finding->components)->unique();
        $items = [];

        foreach ($this->plan->plan($vehicle) as $component) {
            $config = config("maintenance_plan.components.{$component['key']}");
            $due = $component['due_date'] ? Carbon::parse($component['due_date']) : null;
            $inTime = in_array($component['state'], [MaintenancePlanService::OVERDUE, MaintenancePlanService::DUE, MaintenancePlanService::CHECK], true)
                || ($due !== null && $due->lte($until));

            // A check costs nothing; one paid with a finding is counted there.
            if (! $inTime || isset($config['check']) || $covered->contains($component['key'])) {
                continue;
            }

            $price = $this->prices->estimate($vehicle, $component['key']);

            $items[] = $item(
                __($config['label']),
                $config['icon'],
                $price,
                $price === null ? __('Price not known: not paid for before') : __('As last time'),
                $component['state'] === MaintenancePlanService::CHECK ? __('To be checked') : ($due && $due->isPast() ? __('Overdue') : $due?->isoFormat('MMMM YYYY')),
            );
        }

        $mot = $vehicle->rdw_data['vervaldatum_apk_dt'] ?? null;

        if ($mot && Carbon::parse($mot)->lte($until)) {
            $items[] = $item(__('MOT'), 'ic:baseline-security', (float) config('cost_forecast.mot_eur'), __('A usual price'), Carbon::parse($mot)->isoFormat('MMMM YYYY'));
        }

        foreach ($open->filter(fn (Finding $finding) => $finding->estimated_cost) as $finding) {
            $items[] = $item($finding->title, $finding->source->getIcon(), (float) $finding->estimated_cost, __('Estimated with the finding'), $finding->severity->getLabel());
        }

        return $items;
    }

    /**
     * What the maintenance cost a year in the past years, more than what is
     * planned now: repairs nobody saw coming. Null without a year of history.
     */
    private function unforeseen(Vehicle $vehicle, float $planned): ?float
    {
        $since = collect([$vehicle->purchase_date, $vehicle->maintenances->min('date')])->filter()->max();

        if ($since === null) {
            return null;
        }

        $from = CarbonImmutable::parse($since)->max(today()->subYears(self::HISTORY_YEARS));
        $years = $from->diffInDays(today()) / 365.25;

        if ($years < 1) {
            return null;
        }

        $perYear = (float) $vehicle->maintenances->filter(fn ($maintenance) => $maintenance->date->gte($from))->sum('total_price') / $years;

        return $perYear > $planned ? round($perYear - $planned, 2) : null;
    }

    /**
     * What the vehicle cost per kilometer since it was bought, in all and per
     * year: without and, when what it is worth now is known, with what it
     * lost in value.
     *
     * @return array<string, mixed>|null
     */
    public function perKm(Vehicle $vehicle): ?array
    {
        $odometer = Odometer::of($vehicle);
        $start = $vehicle->purchase_date ? CarbonImmutable::parse($vehicle->purchase_date)->startOfDay() : $odometer->firstDay();

        if ($start === null || $start->gte(today())) {
            return null;
        }

        $costs = collect(rescue(fn () => $vehicle->calculateMonthlyCosts($start->toDateString(), today()->toDateString())['monthlyCosts'], [], false))
            ->map(fn (array $month) => array_sum($month));
        $kmAt = fn (CarbonImmutable $day) => $odometer->at($day)[0];
        $startKm = (float) ($vehicle->mileage_start ?: $kmAt($start) ?? 0);
        $nowKm = $kmAt(CarbonImmutable::today()) ?? (float) $vehicle->mileage_latest;
        $km = $nowKm - $startKm;

        if ($km <= 0) {
            return null;
        }

        $total = (float) $costs->sum();
        $value = $this->comparison($vehicle)['price_now_eur'] ?? null;
        $lost = $value !== null && (float) $vehicle->purchase_price > 0 ? max(0, (float) $vehicle->purchase_price - (float) $value) : null;

        $years = collect(range($start->year, today()->year))->map(function (int $year) use ($costs, $kmAt, $start) {
            $from = CarbonImmutable::create($year)->max($start);
            $until = CarbonImmutable::create($year, 12, 31)->min(CarbonImmutable::today());
            $driven = ($kmAt($until) ?? 0) - ($kmAt($from) ?? 0);
            $spent = (float) $costs->filter(fn ($amount, string $month) => str_starts_with($month, (string) $year))->sum();

            return [
                'year' => $year,
                'km' => (int) round(max(0, $driven)),
                'costs' => round($spent, 2),
                'perKm' => $driven > 100 ? round($spent / $driven, 3) : null,
            ];
        })->values()->all();

        return [
            'since' => $start->toDateString(),
            'km' => (int) round($km),
            'costs' => round($total, 2),
            'perKm' => round($total / $km, 3),
            'depreciation' => $lost === null ? null : round($lost, 2),
            'perKmWithDepreciation' => $lost === null ? null : round(($total + $lost) / $km, 3),
            'years' => $years,
        ];
    }

    /**
     * Whether the repairs ahead are still worth it: what is planned in the
     * coming year against what the vehicle is worth now. Null when its worth
     * is not known.
     *
     * @return array{verdict: string, value: float, ahead: float, share: float}|null
     */
    public function repairOrReplace(Vehicle $vehicle, array $forecast): ?array
    {
        $value = $this->comparison($vehicle)['price_now_eur'] ?? null;

        if (! $value) {
            return null;
        }

        // What is known to be ahead: not what may come unforeseen.
        $ahead = (float) collect(collect($forecast['groups'])->firstWhere('key', 'maintenance')['items'] ?? [])
            ->reject(fn (array $item) => $item['unforeseen'] ?? false)
            ->sum('amount');
        $share = $ahead / (float) $value;

        return [
            'verdict' => match (true) {
                $share >= self::REPLACE_SHARE => 'replace',
                $share >= self::WEIGH_SHARE => 'weigh',
                default => 'repair',
            },
            'value' => (float) $value,
            'ahead' => round($ahead, 2),
            'share' => round($share, 2),
        ];
    }

    /**
     * What the comparison of the powertrains estimated of the vehicle itself:
     * its worth now and its loss of value a year.
     *
     * @return array<string, mixed>
     */
    private function comparison(Vehicle $vehicle): array
    {
        return (array) ($vehicle->powertrainComparisons()->latest()->first()?->current ?? []);
    }
}
