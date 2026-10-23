<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\ApiKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;

/**
 * Sorts the tasks of the maintenance history, as the owner or the garage
 * wrote them down, into the components of the maintenance plan. Asked once
 * per vehicle; the owner checks the result before it is stored.
 */
class MaintenanceTaskClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<string, string>  $components  The components, by key, with what they are.
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     */
    public function __construct(
        private readonly array $components,
        private readonly ?string $key = null,
    ) {
        //
    }

    /**
     * With a key of its own, a provider of its own: the keys are tried one by
     * one, see MaintenancePlanAi.
     */
    public function provider(): Provider|string
    {
        return ApiKeys::provider(config('maintenance_plan.provider'), $this->key);
    }

    public function model(): string
    {
        return config('maintenance_plan.model');
    }

    public function timeout(): int
    {
        return config('maintenance_plan.timeout');
    }

    public function instructions(): string
    {
        $components = implode("\n", array_map(
            fn (string $key, string $label) => "- {$key}: {$label}",
            array_keys($this->components),
            $this->components,
        ));

        return <<<TEXT
            You sort out the tasks of the maintenance history of a vehicle, as they were written down, in any
            language and often abbreviated, into the components below. You get the tasks, numbered.

            A task belongs to a component when it replaced or serviced it: "olie + filter" is engine_oil and
            oil_filter, "remvloeistof verversen" is brake_fluid, "nieuwe banden voor" is front_tires, "4 nieuwe
            banden" is front_tires and rear_tires, "remblokken en schijven voor" is front_brake_pads and
            front_brake_discs. Brake shoes are the rear brake pads, brake drums the rear brake discs. When a task
            does not say front or rear, it is both. intake_cleaning is only a cleaning of the intake valves and
            manifold themselves (walnut blasting, a chemical intake cleaning): an injector cleaner or another fuel
            additive does not reach the intake valves of a direct injection engine, so it belongs to none. A task
            that only
            inspected something ("remmen gecontroleerd"), or that is about none of the components (the MOT, washing,
            a repair of the bodywork), belongs to none. When in doubt, choose none: a wrong component makes the
            plan think something was done.

            The components:
            {$components}

            Give every task by its number, with the keys of its components; none when it belongs to none.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tasks' => $schema->array()->items($schema->object([
                'number' => $schema->integer()->required(),
                'components' => $schema->array()->items($schema->string()->enum(array_keys($this->components)))->required(),
            ]))->required(),
        ];
    }

    /**
     * @param  list<string>  $tasks
     */
    public function question(array $tasks): string
    {
        return "Tasks:\n".implode("\n", array_map(fn (int $number, string $task) => ($number + 1).'. '.$task, array_keys($tasks), $tasks));
    }
}
