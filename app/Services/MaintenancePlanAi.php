<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\MaintenancePlanAdvisor;
use App\Ai\Agents\MaintenanceTaskClassifier;
use App\Ai\ApiKeys;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the AI model does for the maintenance plan, once per vehicle: propose
 * the intervals of its components, and sort out which components the tasks of
 * its maintenance history replaced or serviced.
 */
class MaintenancePlanAi
{
    /** Days the proposals for the tasks are kept for the owner to check. */
    private const PROPOSALS_DAYS = 7;

    public function __construct(private readonly MaintenancePlanService $plan)
    {
        //
    }

    /**
     * Has the model propose the intervals of the components, and stores them,
     * except for the components the owner set the intervals of. The number
     * of components it changed.
     *
     * Asked for a single component, its interval is replaced even when the
     * owner set it, and whether the vehicle has it stays as it is: the owner
     * asked for this one.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function propose(Vehicle $vehicle, string $locale, ?string $only = null): int
    {
        $components = $this->plan->components($vehicle)
            ->when($only !== null, fn ($components) => $components->only([$only]));
        $keys = $components->keys()->all();
        $language = VehicleAdviceService::LANGUAGES[$locale] ?? 'English';
        $advisor = fn (?string $key) => new MaintenancePlanAdvisor($language, $keys, $key);

        $response = ApiKeys::ask(config('maintenance_plan.provider'), $advisor, $advisor(null)->question([
            'vehicle' => $this->vehicle($vehicle),
            'components' => $components->map(fn (VehicleComponent $component, string $key) => [
                'what' => config("maintenance_plan.components.{$key}.label"),
                'usual_interval_km' => config("maintenance_plan.components.{$key}.km"),
                'usual_interval_months' => config("maintenance_plan.components.{$key}.months"),
            ])->all(),
        ]));

        $changed = 0;

        foreach ((array) ($response['components'] ?? []) as $proposal) {
            $component = is_array($proposal) ? $components->get((string) ($proposal['key'] ?? '')) : null;

            if ($component === null || ($only === null && $component->source === VehicleComponent::MANUAL)) {
                continue;
            }

            $interval = fn (string $field, int $at_most) => ($value = (int) ($proposal[$field] ?? 0)) > 0 ? min($value, $at_most) : null;

            $component->fill([
                'active' => $only !== null ? $component->active : (bool) ($proposal['applies'] ?? true),
                'interval_km' => $interval('interval_km', 500000),
                'interval_months' => $interval('interval_months', 240),
                'note' => Str::limit(trim(strip_tags((string) ($proposal['note'] ?? ''))), 160) ?: null,
                'source' => VehicleComponent::AI,
            ])->save();

            $changed++;
        }

        return $changed;
    }

    /**
     * What the model gets to know about the vehicle.
     *
     * @return array<string, mixed>
     */
    private function vehicle(Vehicle $vehicle): array
    {
        $rdw = (array) $vehicle->rdw_data;

        return array_filter([
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'version' => $vehicle->version,
            'engine' => $vehicle->engine,
            'transmission' => $vehicle->transmission->value,
            'powertrain' => $vehicle->powertrain,
            'fuel_types' => $vehicle->fuel_types ?: null,
            'construction_date' => $vehicle->construction_date?->toDateString(),
            'mileage_km' => $vehicle->mileage_latest ?: null,
            'body' => $rdw['inrichting'] ?? null,
            'cylinders' => $rdw['aantal_cilinders'] ?? null,
            'engine_capacity_cc' => $rdw['cilinderinhoud'] ?? null,
            'curb_weight_kg' => $rdw['massa_rijklaar'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The tasks of the maintenance history that name no component yet, each
     * once, as they were written down.
     *
     * @return list<string>
     */
    public function unsortedTasks(Vehicle $vehicle): array
    {
        return $vehicle->maintenances()->get()
            ->flatMap(fn (Maintenance $maintenance) => collect($maintenance->tasks ?? [])
                ->filter(fn ($task) => is_array($task) && filled($task['task'] ?? null) && MaintenancePlanService::componentsOf($task) === [])
                ->map(fn (array $task) => trim((string) $task['task'])))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Has the model sort out the tasks that name no component yet, and keeps
     * what it proposes for the owner to check: nothing is stored in the
     * maintenances yet. Only the tasks it found components for.
     *
     * @return array<string, list<string>> The components, by task.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function classify(Vehicle $vehicle): array
    {
        $tasks = array_slice($this->unsortedTasks($vehicle), 0, config('maintenance_plan.classify_at_most'));

        if ($tasks === []) {
            return [];
        }

        $options = $this->plan->components($vehicle)->keys()->mapWithKeys(fn (string $key) => [$key => config("maintenance_plan.components.{$key}.label")])->all();
        $classifier = fn (?string $key) => new MaintenanceTaskClassifier($options, $key);
        $response = ApiKeys::ask(config('maintenance_plan.provider'), $classifier, $classifier(null)->question($tasks));

        $proposals = collect((array) ($response['tasks'] ?? []))
            ->filter(fn ($answer) => is_array($answer) && isset($tasks[(int) ($answer['number'] ?? 0) - 1]))
            ->mapWithKeys(fn (array $answer) => [
                $tasks[(int) $answer['number'] - 1] => array_values(array_intersect((array) ($answer['components'] ?? []), array_keys($options))),
            ])
            ->filter()
            ->all();

        Cache::put($this->proposalsKey($vehicle), $proposals, now()->addDays(self::PROPOSALS_DAYS));

        return $proposals;
    }

    /**
     * What the model proposed for the tasks and the owner did not check yet.
     *
     * @return array<string, list<string>>
     */
    public function proposals(Vehicle $vehicle): array
    {
        return Cache::get($this->proposalsKey($vehicle), []);
    }

    /**
     * Stores the components of the tasks as the owner checked them, in every
     * maintenance with a task written down like that and no components yet.
     * The number of tasks changed.
     *
     * @param  array<string, list<string>>  $assignments  The components, by task.
     */
    public function assign(Vehicle $vehicle, array $assignments): int
    {
        $known = array_keys(config('maintenance_plan.components'));
        $changed = 0;

        foreach ($vehicle->maintenances()->get() as $maintenance) {
            $dirty = false;

            $tasks = collect($maintenance->tasks ?? [])->map(function ($task) use ($assignments, $known, &$dirty, &$changed) {
                $keys = is_array($task) ? array_values(array_intersect($assignments[trim((string) ($task['task'] ?? ''))] ?? [], $known)) : [];

                if ($keys === [] || MaintenancePlanService::componentsOf($task) !== []) {
                    return $task;
                }

                $dirty = true;
                $changed++;

                return [...$task, 'components' => $keys];
            })->all();

            if ($dirty) {
                $maintenance->update(['tasks' => $tasks]);
            }
        }

        Cache::forget($this->proposalsKey($vehicle));

        return $changed;
    }

    private function proposalsKey(Vehicle $vehicle): string
    {
        return "maintenance-plan:{$vehicle->id}:proposals";
    }
}
