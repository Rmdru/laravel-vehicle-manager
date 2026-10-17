<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\ApiKeys;
use App\Models\VehicleAdvice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;

/**
 * Turns everything that is known about a vehicle into a few suggestions the
 * driver can act on. The app calculates the facts; the model connects them,
 * and adds what it knows about the make and model. It cannot look anything
 * up: the free tier has no quota for Google Search.
 */
class VehicleAdvisor implements Agent, HasStructuredOutput
{
    use Promptable;

    /** What each category of VehicleAdvice::CATEGORIES is about. */
    private const CATEGORIES = [
        'maintenance' => 'parts and fluids due by age or mileage that the regular maintenance may not cover (timing belt, brake fluid, coolant, battery, spark plugs, tires).',
        'season' => 'what the coming weeks call for: winter or summer tires, antifreeze, battery before the frost, air conditioning before the summer.',
        'known_issue' => 'weak spots this make, model, engine or year is known for, and what to look out for.',
        'costs' => 'how to pay less: insurance, road tax, fuel consumption that went up, costs that stand out.',
        'value' => 'the resale value: the best moment to sell, or what to do before an expensive MOT or maintenance.',
        'legal' => 'what the law asks: the MOT, insurance, recalls, vignettes and environmental stickers.',
        'usage' => 'how the vehicle is driven and what that asks for.',
    ];

    /** What each action of VehicleAdvice::ACTIONS opens in the app. */
    private const ACTIONS = [
        'add_maintenance' => 'adding a maintenance, for something to have done at the garage.',
        'add_reconditioning' => 'adding a reconditioning (washing, cleaning, protection, damage repair).',
        'view_insurances' => 'the insurances of the vehicle.',
        'view_taxes' => 'the road tax of the vehicle.',
        'view_vehicle' => 'the vehicle itself, its details and specifications.',
        'none' => 'nothing to do in the app.',
    ];

    /**
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     */
    public function __construct(
        private readonly string $language,
        private readonly int $suggestions,
        private readonly ?string $key = null,
    ) {
        //
    }

    /**
     * With a key of its own, a provider of its own: the keys are tried one by
     * one, see VehicleAdviceService.
     */
    public function provider(): Provider|string
    {
        return ApiKeys::provider(config('vehicle_advice.provider'), $this->key);
    }

    public function model(): string
    {
        return config('vehicle_advice.model');
    }

    public function timeout(): int
    {
        return config('vehicle_advice.timeout');
    }

    public function instructions(): string
    {
        $categories = $this->describe(self::CATEGORIES);
        $actions = $this->describe(self::ACTIONS);

        return <<<TEXT
            You advise the owner of a vehicle on what to do about it in the coming months: what to have done, what to
            look out for and how to save money. You get the facts the app knows about the vehicle as JSON.

            Give at most {$this->suggestions} suggestions, the most important first, and only ones that are specific to
            this vehicle, its age, its mileage, its history or the time of year. Never give generic advice that holds
            for any vehicle, such as checking the tire pressure regularly. Fewer good suggestions are better than many;
            give none when nothing stands out.

            already_shown_notifications are shown to the owner already: do not repeat them. You may build on one,
            when you add something it does not say (such as what an MOT of a vehicle this old usually takes).
            dismissed_suggestions were marked by the owner as done or as not relevant: do not make them again, under
            any key, unless the facts show that the situation has clearly changed since.

            Every date, mileage and amount of the vehicle comes from the facts; never invent them. Where you rely on
            what you know about this make, model or engine rather than on the facts (an interval of the manufacturer, a
            known weak spot, a usual price), the basis is general_knowledge; say "usually" or "often", and give a
            price only as a rough range. Where the facts alone make the point, the basis is app_data. The facts may
            have gaps: something not recorded in the maintenance history may well have been done. Then advise to
            check it rather than to have it done.

            The category is one of:
            {$categories}

            The action is what the owner can open in the app for the suggestion, one of:
            {$actions}

            The key is a short, stable name of what the suggestion is about, in English snake_case (such as
            timing_belt, winter_tires or insurance_comparison): the same subject gets the same key next time.
            The priority is high only when ignoring it soon costs money, safety or a fine. The due date (YYYY-MM-DD)
            is when to act, never in the past; an empty string when there is no such date.

            Write the title and the body in {$this->language}, plainly, to the owner. The title is at most 70
            characters. The body is two or three short sentences of plain text: no Markdown, no links.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestions' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'category' => $schema->string()->enum(VehicleAdvice::CATEGORIES)->required(),
                'priority' => $schema->string()->enum(VehicleAdvice::PRIORITIES)->required(),
                'basis' => $schema->string()->enum(VehicleAdvice::BASES)->required(),
                'action' => $schema->string()->enum(VehicleAdvice::ACTIONS)->required(),
                'due_date' => $schema->string()->description('YYYY-MM-DD, or empty.')->required(),
                'title' => $schema->string()->required(),
                'body' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function question(array $facts): string
    {
        return "Facts:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, string>  $meanings
     */
    private function describe(array $meanings): string
    {
        return implode("\n", array_map(fn (string $name, string $meaning) => '- '.$name.': '.$meaning, array_keys($meanings), $meanings));
    }
}
