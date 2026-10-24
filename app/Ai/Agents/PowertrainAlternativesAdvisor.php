<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\ApiKeys;
use App\Enums\BodyType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;

/**
 * Chooses a realistic alternative model per powertrain for a vehicle, and
 * estimates for it and for the vehicle itself what the app cannot know: the
 * consumption in practice, the range, the price and the yearly costs. The app
 * calculates the comparison from these, on the use of the owner.
 */
class PowertrainAlternativesAdvisor implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  array<string, string>  $powertrains  The powertrains to choose an alternative for, by key, with their name.
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     * @param  string|null  $model  The model to ask; null for the configured one.
     */
    public function __construct(
        private readonly string $language,
        private readonly array $powertrains,
        private readonly ?string $key = null,
        private readonly ?string $model = null,
    ) {
        //
    }

    /**
     * With a key of its own, a provider of its own: the keys are tried one by
     * one, see PowertrainComparisonService.
     */
    public function provider(): Provider|string
    {
        return ApiKeys::provider(config('powertrain_comparison.provider'), $this->key);
    }

    public function model(): string
    {
        return $this->model ?? config('powertrain_comparison.model');
    }

    public function timeout(): int
    {
        return config('powertrain_comparison.timeout');
    }

    public function instructions(): string
    {
        $powertrains = collect($this->powertrains)->map(fn (string $name, string $key) => "- {$key}: {$name}")->implode("\n");
        $bodyTypes = collect(BodyType::cases())->map(fn (BodyType $type) => "{$type->value} ({$type->englishName()})")->implode(', ');

        return <<<TEXT
            You help the owner of a vehicle compare it with other powertrains. You get the vehicle and how it is used,
            as JSON. Go through the powertrains below one by one, and for each choose one realistic alternative
            model that someone with this vehicle would consider instead:
            - the same segment and size, and the same body type: the body_type of the vehicle when given, otherwise
              the one its model and version have. A hatchback stays a hatchback, a station wagon a station wagon, an
              SUV an SUV. Many models come in more than one (such as a Seat Leon hatchback and Sportstourer, or a
              Golf and a Golf Variant): take the alternative in that same body type. Only when no comparable car has
              the powertrain in that body type, take the closest one and say so in the reason,
            - a power within 20% of that of the vehicle (its power_hp when given, otherwise the one of its version):
              choose the version of the model that comes closest (such as an eHybrid of 204 hp rather than a GTE of
              245 hp for a car of 150 hp),
            - the same build year. When the powertrain did not exist yet in that segment in that year, take the
              first year that had it, and give that build year.
            The same model with another engine is often the best choice (such as a CNG or a diesel version of it);
            otherwise a direct competitor. Prefer a model sold in the country of the owner. Give an alternative for
            every powertrain below for which one exists in or near the segment, also when it is a rare one (such as
            a factory LPG or CNG version). Only leave a powertrain out when no car of a comparable class has it at
            all (such as hydrogen, or a diesel plug-in hybrid, for most cars): never stretch to a car of another
            class just to fill it.

            {$powertrains}

            For the vehicle itself (current) and for every alternative, estimate:
            - fuel_per_100km: the consumption in practice, as the average driver of the model gets it (such as the
              averages of Spritmonitor), not the WLTP figure. In liters per 100 km, but in kilograms for CNG and
              hydrogen; of LPG for an LPG car; with an empty battery for a plug-in hybrid. 0 for an electric car.
            - electricity_kwh_per_100km: the consumption in practice from the charger, charging losses included,
              of an electric car or of a plug-in hybrid driving electric. 0 for anything else.
            - wltp_electric_range_km: the WLTP range of an electric car, or the electric WLTP range of a plug-in
              hybrid. 0 for anything else.
            - tank_capacity: the fuel tank in liters (of LPG for an LPG car), or in kilograms for CNG and
              hydrogen. 0 for an electric car. gasoline_tank_l: the gasoline tank next to the LPG or CNG tank, 0 for
              anything else.
            - price_now_eur: what it costs to buy now, used, of that build year with the mileage the vehicle has
              (more or less), in the country of the owner. For the vehicle itself: what it is worth now.
            - depreciation_per_year_eur: how much it loses in value in the coming year, at the kilometers a year
              of the owner.
            - maintenance_per_year_eur: maintenance and repairs a year at the kilometers a year of the owner,
              tires and brakes included. Electric cars are not maintenance free: they lose the engine oil, the
              filters other than the cabin filter, the spark plugs, the belts, the clutch and the exhaust, but their
              weight and torque wear the tires and the suspension faster, their brakes rust from being little used,
              and the 12 volt battery, the brake fluid, the coolant and the air conditioning need the same care.
              Count what applies; never assume an electric car costs (almost) nothing to maintain. A car on LPG or
              CNG has its gas system to maintain on top of the engine (filters, the vaporizer, the periodic check
              of the tank): count it too.
            - insurance_per_year_eur: the premium a year with the same cover and driver as the vehicle itself; when
              usage gives the current premium, take it as the starting point; the same for the road tax.
            - road_tax_per_year_eur: the road tax a year in the country (and province, from the city) of the owner,
              by the rules of today (see today in the facts), with the weight, the fuel and the emissions of the
              model. Electric cars and plug-in hybrids are not exempt everywhere: in the Netherlands they pay road
              tax again since 2025, with a discount that shrinks until 2029, and diesels and LPG cars pay a
              surcharge.
            - power_hp: the power in hp, and build_year: the year it was built.
            - body_type: the body type, one of: {$bodyTypes}.

            For the vehicle itself, take the facts that are given (the tank, the power, the year) as they are. For
            the alternatives give brand, model and version as they are sold (such as "Volkswagen", "Golf",
            "1.4 eHybrid 204 pk"), and the reason: why it is a fair comparison and how it differs from the vehicle
            (size, power, practicality), factually, in one short sentence of at most 140 characters in
            {$this->language}. Never say what the costs are: the app shows them. The owner cannot charge at home
            (only at public chargers, possibly one near home): never call a car a good choice for charging at home. Every amount is in euros, rounded
            to tens.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        $estimates = fn () => [
            'build_year' => $schema->integer()->required(),
            'power_hp' => $schema->integer()->required(),
            'fuel_per_100km' => $schema->number()->required(),
            'electricity_kwh_per_100km' => $schema->number()->required(),
            'wltp_electric_range_km' => $schema->integer()->required(),
            'tank_capacity' => $schema->number()->required(),
            'gasoline_tank_l' => $schema->number()->required(),
            'price_now_eur' => $schema->integer()->required(),
            'depreciation_per_year_eur' => $schema->integer()->required(),
            'maintenance_per_year_eur' => $schema->integer()->required(),
            'insurance_per_year_eur' => $schema->integer()->required(),
            'road_tax_per_year_eur' => $schema->integer()->required(),
            'body_type' => $schema->string()->enum(array_column(BodyType::cases(), 'value'))->required(),
        ];

        return [
            'current' => $schema->object($estimates())->required(),
            'alternatives' => $schema->array()->items($schema->object([
                'powertrain' => $schema->string()->enum(array_keys($this->powertrains))->required(),
                'brand' => $schema->string()->required(),
                'model' => $schema->string()->required(),
                'version' => $schema->string()->required(),
                ...$estimates(),
                'reason' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function question(array $facts): string
    {
        return "Vehicle and use:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
