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
        'costs' => 'how to save money wisely: preventing expensive future damage through timely small investments, insurance, road tax, fuel consumption that went up, or costs that stand out.',
        'value' => "condition, preservation and economic sanity: keeping the vehicle's mechanical and structural condition sound, or realistically advising when repairs no longer make financial sense compared to vehicle value.",
        'legal' => 'what the law asks: the MOT, insurance, recalls, vignettes and environmental stickers.',
        'usage' => 'how the vehicle is driven (such as frequent short trips vs long highway drives) and what that asks for in maintenance or habits.',
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
        $icons = $this->describe(VehicleAdvice::ICONS);

        return <<<TEXT
            You advise the owner of a vehicle on keeping it reliable, safe, and in good running condition for as long as
            possible, at the lowest total cost over time. Balance acute actions needed in the coming months with proactive
            long-term preservation and preventing expensive consequential damage. You get the facts the app knows about
            the vehicle as JSON.

            Give at most {$this->suggestions} suggestions, the most important first, and only ones that are specific to
            this vehicle, its age, its mileage, its history or the time of year. Never give generic advice that holds
            for any vehicle, such as checking the tire pressure regularly. Fewer good suggestions are much better than
            many; give only 1, 2, or none at all when nothing new or pressing stands out. Do not add filler tips.

            already_shown_notifications are shown to the owner already: do not repeat them. You may build on one,
            when you add something it does not say (such as what an MOT of a vehicle this old usually takes).
            maintenance_plan are the components the app tracks, with their intervals, when they were done last and
            when they are due again; the app shows the owner when one is due already, so a tip that only says that
            adds nothing. A tip about a component of the plan is welcome when it adds something: a known weak spot of
            it on this model, combining it with another job or the MOT, what to ask the garage, or how to save on
            it. Bring up as well, from what you know about this make, model and engine, what the plan does not cover
            or where it falls short: a part outside it, or an interval of the manufacturer that differs from the
            plan's. Keep in mind that official manufacturer schedules (such as long-life oil intervals or 'sealed-for-life'
            transmissions) are often designed for lease periods rather than 200,000+ km longevity: point out where
            more frequent fluid changes (engine oil, transmission, differential) protect the powertrain.
            vehicle.maintenance_intervals are the service intervals the owner set for this vehicle: per interval the
            maintenance types that restart it, and the interval in km and in months, whichever comes first. Types that
            share an interval (such as oil_service and inspection_service) are always done together, at one visit.
            next_maintenances tells per interval when it was done last and when it is due again. Take them as the
            owner's schedule: plan service jobs, checks and repairs in with the next service rather than advising a
            separate visit, and point out where an interval is longer than this make, model or engine needs for a
            long life, or where it differs from what the manufacturer gives.
            handled_suggestions are tips the owner already did something with: done, kept (the owner knows, and
            looks the tip up when needed) or not_relevant. Do not make them again, under any key, and avoid re-suggesting
            the same component, theme or subject under different phrasing, unless the facts show that the situation
            has clearly changed since (such as a new season for a seasonal tip that was done last year).
            open_findings are what needs attention according to the garage, the owner or the MOT, until it is
            resolved; the app shows the urgent ones already. When on_the_list_for_the_garage is true, the owner already
            planned to have the garage look at it: never tell them to go to the garage for it or to put it on their list.
            A tip about it is only welcome when it helps prepare for that visit (what specific question to ask the mechanic
            to save diagnostic time, what it usually costs, or inspecting a related wear part while labor is underway).
            When a finding is not on the list yet, advise putting it on the list if it can be combined with upcoming
            maintenance or MOT, or what happens when it is left; do not only repeat it. Defects of the MOT are for
            information: the garage may have repaired them at once.
            top_ups tell how much engine oil the engine uses, and how often coolant or brake fluid was topped up: the
            app warns when that is a lot; a tip may explain what it usually means for this engine.
            previous_suggestions are the tips of the previous advice the owner did not handle yet. Do not simply
            repeat them: the owner has seen them already. Prefer rotating them out in favor of fresh angles or fewer
            suggestions. Only repeat an unhandled suggestion if it is urgent (high priority) or a critical deadline
            or mileage is approaching. When you do repeat one, keep its key.

            mileage tells when the mileage was recorded last. When that is a while ago, estimated_today_km is what
            the app estimates from the usual distance per month: use that for what is due by mileage, and say that
            it is an estimate.
            usage shows how the vehicle is driven: connect the driving pattern to vehicle health (such as risks of frequent
            short trips causing oil dilution, carbon buildup, battery drain or DPF clogging, and what to do about it).
            planned_trips are the trips planned with this vehicle in the coming weeks. Advise on what the vehicle
            needs before such a trip: maintenance that falls due before or during it, tires and fluids before a
            long drive, a vignette or environmental sticker for a country on the way that
            valid_vignettes_and_stickers does not cover, or the winter equipment a country asks for in that season.
            The trip planner gives the travel tips themselves (the route, stops, traffic): leave those out.
            weather_forecast is the forecast of the coming days where the driver lives. Only base a seasonal tip on it
            when it shows an impending, concrete event (such as the first frost, snow or a hot spell) with a specific
            date; do not give generic seasonal reminders based merely on the month.

            Avoid false economy when advising on costs: a modest preventative investment (such as a timely fluid flush,
            inspecting a known weak spot or replacing a worn seal) is often vastly cheaper than the catastrophic engine
            or transmission damage it prevents. value focuses on preserving structural and mechanical health for the
            long run, but remains economically realistic: if impending repairs or chronic issues clearly exceed the
            vehicle's realistic worth, advise honestly whether keeping it remains economically sensible.

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

            The icon is the one that shows best what the suggestion is about, one of:
            {$icons}

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
                'icon' => $schema->string()->enum(array_keys(VehicleAdvice::ICONS))->required(),
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
