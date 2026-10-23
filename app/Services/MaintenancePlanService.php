<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Support\Odometer;
use App\Support\StatusNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The maintenance plan of a vehicle: per component when it was done last,
 * from the maintenances whose tasks name it, and when it is due again, by
 * time and by distance at the pace the vehicle is driven.
 */
class MaintenancePlanService
{
    /** Done in time, nothing to do yet. */
    public const OK = 'ok';

    /** Due within soon_days or soon_km. */
    public const SOON = 'soon';

    /** Due within due_days or due_km. */
    public const DUE = 'due';

    /** Past its interval. */
    public const OVERDUE = 'overdue';

    /**
     * Never recorded, and counted from new it is overdue: to be checked, as it
     * may well have been done.
     */
    public const CHECK = 'check';

    /** Never recorded, and the construction date is not known either. */
    public const UNKNOWN = 'unknown';

    /** Without an interval: only done when needed. */
    public const NO_INTERVAL = 'no_interval';

    /** The states, the most urgent first. */
    public const STATES = [self::OVERDUE, self::DUE, self::CHECK, self::UNKNOWN, self::SOON, self::OK, self::NO_INTERVAL];

    /** Components to be checked that are put together in a single notification, at least. */
    private const CHECKS_TOGETHER = 2;

    /**
     * Whether a component of the catalog applies to the powertrain of a vehicle.
     *
     * @param  array{for: string}  $component
     */
    public static function applies(Vehicle $vehicle, array $component): bool
    {
        return match ($component['for']) {
            'engine' => ! in_array($vehicle->powertrain, ['electricity', 'hydrogen'], true),
            'spark_ignition' => str_starts_with((string) $vehicle->powertrain, 'gasoline'),
            default => true,
        };
    }

    /**
     * The components of the catalog that apply to the vehicle, by key, with
     * their label translated: to choose from for a task.
     *
     * @return array<string, string>
     */
    public static function options(?Vehicle $vehicle): array
    {
        return collect(config('maintenance_plan.components'))
            ->filter(fn (array $component) => $vehicle === null || self::applies($vehicle, $component))
            ->map(fn (array $component) => __($component['label']))
            ->all();
    }

    /**
     * The components of the vehicle that apply to it, by key: as stored, or
     * with the intervals of the catalog when they were never changed. Nothing
     * is stored by asking.
     *
     * @return Collection<string, VehicleComponent>
     */
    public function components(Vehicle $vehicle): Collection
    {
        $stored = $vehicle->components()->get()->keyBy('key');

        return collect(config('maintenance_plan.components'))
            ->filter(fn (array $component) => self::applies($vehicle, $component))
            ->map(fn (array $component, string $key) => $stored->get($key) ?? new VehicleComponent([
                'vehicle_id' => $vehicle->id,
                'key' => $key,
                'active' => $component['active'] ?? true,
                'interval_km' => $component['km'],
                'interval_months' => $component['months'],
                'source' => VehicleComponent::DEFAULT,
            ]));
    }

    /**
     * The keys of the components a task of a maintenance names, as far as they are known.
     *
     * @param  array<string, mixed>  $task
     * @return list<string>
     */
    public static function componentsOf(array $task): array
    {
        return array_values(array_intersect((array) ($task['components'] ?? []), array_keys(config('maintenance_plan.components'))));
    }

    /**
     * When each component was done last according to the maintenances: the
     * latest one with a task that names it. The air conditioning service is
     * also done with an airco check, a check with a maintenance of its type.
     *
     * @return array<string, array{date: CarbonImmutable, km: int|null, price: float|null, garage: string|null, maintenance_id: string}>
     */
    private function lastDone(Vehicle $vehicle): array
    {
        $done = [];
        $checks = collect(config('maintenance_plan.components'))->filter(fn (array $component) => isset($component['check']))->map(fn (array $component) => $component['check']);

        foreach ($vehicle->maintenances->sortBy('date') as $maintenance) {
            /** @var Maintenance $maintenance */
            $tasks = collect($maintenance->tasks ?? [])->filter(fn ($task) => is_array($task));
            $keys = $tasks->flatMap(fn (array $task) => self::componentsOf($task));

            if ($maintenance->airco_check) {
                $keys->push('airco_service');
            }

            if ($check = $checks->search($maintenance->type_maintenance)) {
                $keys->push($check);
            }

            foreach ($keys->unique() as $key) {
                $task = $tasks->first(fn (array $task) => in_array($key, self::componentsOf($task), true));

                $done[$key] = [
                    'date' => CarbonImmutable::parse($maintenance->date)->startOfDay(),
                    'km' => $maintenance->mileage ? (int) $maintenance->mileage : null,
                    'price' => isset($task['price']) && (float) $task['price'] > 0 ? (float) $task['price'] : null,
                    'garage' => $maintenance->garage ?: null,
                    'maintenance_id' => $maintenance->id,
                ];
            }
        }

        return $done;
    }

    /**
     * The plan: every active component with when it was done last, when it
     * is due again and how urgent it is, the most urgent first.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(Vehicle $vehicle): array
    {
        $vehicle->loadMissing(['maintenances', 'refuelings']);

        $odometer = Odometer::of($vehicle);
        [$mileage] = $odometer->at(CarbonImmutable::today());
        $done = $this->lastDone($vehicle);
        $states = array_flip(self::STATES);

        $new = $vehicle->construction_date ? CarbonImmutable::parse($vehicle->construction_date)->startOfDay() : null;

        return $this->components($vehicle)
            ->filter(fn (VehicleComponent $component) => $component->active)
            ->map(fn (VehicleComponent $component) => $this->status($component, $done[$component->key] ?? null, $odometer, $mileage, $new))
            ->sortBy([
                fn (array $a, array $b) => $states[$a['state']] <=> $states[$b['state']],
                fn (array $a, array $b) => ($a['due_date'] ?? '9999') <=> ($b['due_date'] ?? '9999'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{date: CarbonImmutable, km: int|null, price: float|null, garage: string|null, maintenance_id: string}|null  $done
     * @param  CarbonImmutable|null  $new  The construction date: what is never recorded is counted from it.
     * @return array<string, mixed>
     */
    private function status(VehicleComponent $component, ?array $done, Odometer $odometer, ?float $mileage, ?CarbonImmutable $new): array
    {
        // What the owner set goes first when it is more recent than the maintenances.
        if ($component->done_date !== null && ($done === null || $component->done_date->gt($done['date']))) {
            $done = [
                'date' => CarbonImmutable::parse($component->done_date)->startOfDay(),
                'km' => $component->done_km,
                'price' => null,
                'garage' => null,
                'maintenance_id' => null,
            ];
        }

        // Never recorded: the original one is assumed to be in it still, from new at 0 km.
        $assumed = $done === null && $new !== null;
        $from = $assumed ? ['date' => $new, 'km' => 0] : $done;

        $byTime = $from && $component->interval_months ? $from['date']->addMonthsNoOverflow($component->interval_months) : null;
        $dueKm = $from && $from['km'] !== null && $component->interval_km ? $from['km'] + $component->interval_km : null;
        [$byDistance, $estimated] = $dueKm !== null ? $odometer->dayOf($dueKm) : [null, false];
        $dueDate = collect([$byTime, $byDistance])->filter()->min();
        $remainingKm = $dueKm !== null && $mileage !== null ? (int) round($dueKm - $mileage) : null;
        $state = $this->state($component, $from, $dueDate, $remainingKm);

        // Overdue going by its age only: it may well have been done, so to be checked.
        if ($assumed && $state === self::OVERDUE) {
            $state = self::CHECK;
        }

        return [
            'key' => $component->key,
            'interval_km' => $component->interval_km,
            'interval_months' => $component->interval_months,
            'source' => $component->source,
            'note' => $component->note,
            // When it was done last as the owner filled it in.
            'own_done_date' => $component->done_date?->toDateString(),
            'own_done_km' => $component->done_km,
            'done' => $done === null ? null : [...$done, 'date' => $done['date']->toDateString()],
            // Counted from the construction date, as it was never recorded.
            'assumed_since' => $assumed ? $new->toDateString() : null,
            'due_date' => $dueDate?->toDateString(),
            'due_km' => $dueKm,
            'due_by' => $dueDate === null ? null : ($byDistance !== null && $dueDate->eq($byDistance) ? 'distance' : 'time'),
            'estimated' => $byDistance !== null && $dueDate?->eq($byDistance) && $estimated,
            'remaining_km' => $remainingKm,
            'state' => $state,
        ];
    }

    /**
     * Records that a component was done today, at the current mileage, without
     * a form: a check as a maintenance of its own type, anything else as a
     * maintenance with a task for it.
     */
    public function done(Vehicle $vehicle, string $key): Maintenance
    {
        $component = config("maintenance_plan.components.{$key}");

        return $vehicle->maintenances()->create([
            'type_maintenance' => $component['check'] ?? null,
            'date' => today()->toDateString(),
            'mileage' => $vehicle->mileage_latest ?: $vehicle->mileage_start ?: 0,
            'tasks' => isset($component['check']) ? null : [['task' => __($component['label']), 'price' => null, 'components' => [$key]]],
        ]);
    }

    /**
     * The components that need attention, as notifications under "Needs
     * attention": red when overdue or due, orange when due soon. The ones to
     * be checked as well: red when expensive or dangerous to miss, and each
     * check of its own with a button to tick it off; the others together in
     * one notification, rather than a whole list at once for an older vehicle.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        $plan = collect($this->plan($vehicle));
        $toCheck = $plan->where('state', self::CHECK);
        $alone = fn (array $item) => config("maintenance_plan.components.{$item['key']}.critical") || config("maintenance_plan.components.{$item['key']}.check");
        $together = $toCheck->reject($alone);

        return collect([
            ...$plan->filter(fn (array $item) => in_array($item['state'], [self::OVERDUE, self::DUE, self::SOON], true))
                ->map(fn (array $item) => $this->notification($item, $item['state'] === self::SOON ? 'warning' : 'critical')),
            ...$toCheck->filter($alone)
                ->map(fn (array $item) => $this->notification($item, config("maintenance_plan.components.{$item['key']}.critical") ? 'critical' : 'warning')),
            ...($together->count() >= self::CHECKS_TOGETHER
                ? [$this->together($together->count())]
                : $together->map(fn (array $item) => $this->notification($item, 'warning'))),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function notification(array $item, string $type): array
    {
        $types = StatusNotification::types();
        $component = config("maintenance_plan.components.{$item['key']}");
        $label = __($component['label']);
        $due = $item['due_date'] ? CarbonImmutable::parse($item['due_date']) : null;
        $check = $item['state'] === self::CHECK;

        return [
            ...$types[$type],
            'key' => 'maintenance_plan:'.$item['key'],
            'type' => $type,
            'text' => match ($item['state']) {
                self::CHECK => __(':component: not known when it was done last, going by its age and mileage it is due already', ['component' => $label]),
                self::OVERDUE => __(':component is overdue', ['component' => $label]),
                default => __(':component is due :when', ['component' => $label, 'when' => $due?->isoFormat('D MMMM') ?? '']),
            },
            'icon' => $component['icon'],
            'typeIcon' => $types[$type]['icon'],
            'hasModal' => false,
            'data' => [],
            // To be checked: when it was done is filled in on the plan.
            'url' => $check ? route('maintenance-plan', ['edit' => $item['key']]) : route('maintenance-plan'),
            'urlLabel' => $check ? __('Fill in when') : __('Maintenance plan'),
            // A check, or one to be checked, is ticked off from the notification itself.
            'doneUrl' => $check || isset($component['check']) ? route('maintenance-plan.done', $item['key']) : null,
        ];
    }

    /**
     * The components to be checked, together in one notification.
     *
     * @return array<string, mixed>
     */
    private function together(int $count): array
    {
        $types = StatusNotification::types();

        return [
            ...$types['warning'],
            'key' => 'maintenance_plan:check',
            'type' => 'warning',
            'text' => __(':count components: not known when they were done last, going by their age and mileage they may be due already', ['count' => $count]),
            'icon' => 'mdi:clipboard-list-outline',
            'typeIcon' => $types['warning']['icon'],
            'hasModal' => false,
            'data' => [],
            'url' => route('maintenance-plan'),
            'urlLabel' => __('Maintenance plan'),
        ];
    }

    private function state(VehicleComponent $component, ?array $done, ?CarbonImmutable $dueDate, ?int $remainingKm): string
    {
        if (! $component->interval_km && ! $component->interval_months) {
            return self::NO_INTERVAL;
        }

        if ($done === null) {
            return self::UNKNOWN;
        }

        $days = $dueDate === null ? null : CarbonImmutable::today()->diffInDays($dueDate, false);
        // A check is due within days rather than weeks.
        $within = fn (string $kind) => ($days !== null && $days <= (config("maintenance_plan.components.{$component->key}.{$kind}_days") ?? config("maintenance_plan.{$kind}_days")))
            || ($remainingKm !== null && $remainingKm <= config("maintenance_plan.{$kind}_km"));

        return match (true) {
            ($days !== null && $days < 0) || ($remainingKm !== null && $remainingKm < 0) => self::OVERDUE,
            $within('due') => self::DUE,
            $within('soon') => self::SOON,
            default => self::OK,
        };
    }
}
