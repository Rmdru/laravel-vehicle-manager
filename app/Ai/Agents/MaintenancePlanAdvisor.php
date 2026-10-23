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
 * Proposes the maintenance intervals of the components of a vehicle, as the
 * manufacturer schedules them for its make, model, engine and year. Asked
 * once per vehicle: the owner can change the intervals afterwards.
 */
class MaintenancePlanAdvisor implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  list<string>  $keys  The components to propose an interval for.
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     */
    public function __construct(
        private readonly string $language,
        private readonly array $keys,
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
        return <<<TEXT
            You set up the maintenance plan of a vehicle: per component how often it has to be replaced or serviced,
            as the manufacturer schedules it for this make, model, engine and year. You get the vehicle and the
            components, with the usual intervals as a starting point, as JSON.

            For every component give:
            - applies: false when this vehicle does not have it, or never needs it replaced. Such as a timing chain
              instead of a timing belt, no spark plugs in a diesel, no separate fuel filter, or gearbox oil that is
              filled for life. intake_cleaning (removing the carbon deposits from the intake valves and manifold)
              applies only to a direct injection engine (such as TSI, TFSI, GDI or a common rail diesel with exhaust
              gas recirculation); not to one with port injection. An injector cleaner in the fuel does not replace it. The clutch applies to a manual or a dual clutch
              gearbox (such as DSG), not to an automatic with a torque converter; give its usual life as interval_km.
              With drum brakes at the rear, rear_brake_pads stands for the brake shoes and rear_brake_discs for the
              drums: give their intervals, and say "drum brakes" in the note. Tires and brakes wear differently at the
              front and the rear: on a front wheel drive the front ones go first.
              The washer fluid is topped up rather than replaced: give how often to check it, with winter fluid
              before the frost.
              Electric vehicles need maintenance too: the cabin filter, the brake fluid, the coolant
              of the battery and the 12 volt battery do apply to them. Regenerative braking makes brake pads last
              longer, not forever.
            - interval_km and interval_months: the interval of the manufacturer, whichever comes first; 0 for no
              interval of that kind. Where a variable (longlife) schedule applies, give its usual maximum.
            - note: why, in one short sentence of at most 120 characters, written in {$this->language}; such as "Ketting:
              geen vast interval, laten controleren bij ratelen" or "Volgens Longlife-schema maximaal 2 jaar". Empty
              when the interval speaks for itself.

            You cannot look anything up: rely on what you know about this make, model and engine. When you do not
            know it for this vehicle, keep the usual interval and say so in the note.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'components' => $schema->array()->items($schema->object([
                'key' => $schema->string()->enum($this->keys)->required(),
                'applies' => $schema->boolean()->required(),
                'interval_km' => $schema->integer()->required(),
                'interval_months' => $schema->integer()->required(),
                'note' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function question(array $facts): string
    {
        return "Vehicle and components:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
