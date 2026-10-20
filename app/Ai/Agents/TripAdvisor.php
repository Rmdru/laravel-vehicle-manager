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
 * Turns a planned trip into a few tips for the road: what the countries on the
 * way ask of a driver, what to look out for at this time of year, and what to
 * prepare. The app calculates the trip; the model adds what it knows.
 */
class TripAdvisor implements Agent, HasStructuredOutput
{
    use Promptable;

    /** What each category of tips is about. */
    public const CATEGORIES = [
        'rules' => 'what the law asks in the countries on the way: required equipment (warning vest, triangle, breathalyser), speed limits, alcohol limits, rules that differ from home.',
        'costs' => 'toll, vignettes and other charges on the way, and how to pay them.',
        'timing' => 'busy days and hours on the way (holidays, weekend traffic, rush hours around big cities), and when to drive.',
        'season' => 'what the time of year asks: weather, daylight, winter equipment.',
        'vehicle' => 'what to check or prepare on the vehicle before this trip.',
        'comfort' => 'making the trip easier: breaks, overnight stays, what to take along.',
    ];

    public function __construct(
        private readonly string $language,
        private readonly int $tips,
        private readonly ?string $key = null,
    ) {
        //
    }

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
        $categories = implode("\n", array_map(fn (string $name, string $meaning) => '- '.$name.': '.$meaning, array_keys(self::CATEGORIES), self::CATEGORIES));

        return <<<TEXT
            You give a driver tips for a trip by car the app has planned. You get the facts of the trip as JSON: the
            countries on the way, the dates and times, the roads, the toll and vignettes, the planned stops, the
            vehicle and what the app already warns about.

            Give at most {$this->tips} tips, the most useful first, and only ones that are specific to this trip: its
            countries, its roads, its dates, its vehicle. Never give generic advice that holds for any trip, such as
            taking enough rest. The app already plans the breaks, meals, fuel and overnight stays, and already shows
            the notices in the facts: do not repeat them; you may add what they leave out.

            Every date, time, distance and amount of the trip comes from the facts; never invent them. Rules and
            prices change: give a price only as a rough range, and say to check rules that may have changed. You
            cannot look anything up.

            The category is one of:
            {$categories}

            Write the title and the body in {$this->language}, plainly, to the driver. The title is at most 70
            characters. The body is two or three short sentences of plain text: no Markdown, no links.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tips' => $schema->array()->items($schema->object([
                'category' => $schema->string()->enum(array_keys(self::CATEGORIES))->required(),
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
}
