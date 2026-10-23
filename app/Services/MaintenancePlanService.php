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

    /** Never recorded: when it was done last is not known. */
    public const UNKNOWN = 'unknown';

    /** Without an interval: only done when needed. */
    public const NO_INTERVAL = 'no_interval';

    /** The states, the most urgent first. */
    public const STATES = [self::OVERDUE, self::DUE, self::UNKNOWN, self::SOON, self::OK, self::NO_INTERVAL];

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

        return $this->components($vehicle)
            ->filter(fn (VehicleComponent $component) => $component->active)
            ->map(fn (VehicleComponent $component) => $this->status($component, $done[$component->key] ?? null, $odometer, $mileage))
            ->sortBy([
                fn (array $a, array $b) => $states[$a['state']] <=> $states[$b['state']],
                fn (array $a, array $b) => ($a['due_date'] ?? '9999') <=> ($b['due_date'] ?? '9999'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{date: CarbonImmutable, km: int|null, price: float|null, garage: string|null, maintenance_id: string}|null  $done
     * @return array<string, mixed>
     */
    private function status(VehicleComponent $component, ?array $done, Odometer $odometer, ?float $mileage): array
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

        $byTime = $done && $component->interval_months ? $done['date']->addMonthsNoOverflow($component->interval_months) : null;
        $dueKm = $done && $done['km'] !== null && $component->interval_km ? $done['km'] + $component->interval_km : null;
        [$byDistance, $estimated] = $dueKm !== null ? $odometer->dayOf($dueKm) : [null, false];
        $dueDate = collect([$byTime, $byDistance])->filter()->min();
        $remainingKm = $dueKm !== null && $mileage !== null ? (int) round($dueKm - $mileage) : null;

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
            'due_date' => $dueDate?->toDateString(),
            'due_km' => $dueKm,
            'due_by' => $dueDate === null ? null : ($byDistance !== null && $dueDate->eq($byDistance) ? 'distance' : 'time'),
            'estimated' => $byDistance !== null && $dueDate?->eq($byDistance) && $estimated,
            'remaining_km' => $remainingKm,
            'state' => $this->state($component, $done, $dueDate, $remainingKm),
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
     * The components that are overdue or due, as notifications under "Needs
     * attention". The ones never recorded are left out: a new plan would
     * fill it at once.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        $types = StatusNotification::types();

        return collect($this->plan($vehicle))
            ->filter(fn (array $item) => in_array($item['state'], [self::OVERDUE, self::DUE], true))
            ->map(function (array $item) use ($types) {
                $type = $item['state'] === self::OVERDUE ? 'warning' : 'info';
                $component = config("maintenance_plan.components.{$item['key']}");
                $due = $item['due_date'] ? CarbonImmutable::parse($item['due_date']) : null;

                return [
                    ...$types[$type],
                    'key' => 'maintenance_plan:'.$item['key'],
                    'type' => $type,
                    'text' => $item['state'] === self::OVERDUE
                        ? __(':component is overdue', ['component' => __($component['label'])])
                        : __(':component is due :when', ['component' => __($component['label']), 'when' => $due?->isoFormat('D MMMM') ?? '']),
                    'icon' => $component['icon'],
                    'typeIcon' => $types[$type]['icon'],
                    'hasModal' => false,
                    'data' => [],
                    'url' => route('maintenance-plan'),
                    'urlLabel' => __('Maintenance plan'),
                    // A check is ticked off from the notification itself.
                    'doneUrl' => isset($component['check']) ? route('maintenance-plan.done', $item['key']) : null,
                ];
            })
            ->values()
            ->all();
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
