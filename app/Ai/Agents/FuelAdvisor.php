<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\FuelAdvice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;

/**
 * Turns the calculated facts into advice on when and where to refuel. The
 * numbers come from the facts; the web (Google Search, or the pages it is
 * given) only adds what is coming up.
 */
class FuelAdvisor implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    /** What to look up on the web. */
    private const NEWS = 'what the prices do not show yet: announced changes of excise duties or VAT, announced '
        .'official maximum prices (Belgium and Luxembourg publish them in advance), the recent trend of crude oil '
        .'prices, and holidays or events in the coming days that push the prices up';

    /** What each action of FuelAdvice::ACTIONS means. */
    private const ACTIONS = [
        'refuel_now' => 'refuel today or tomorrow, because the tank is (nearly) due or the prices are about to rise.',
        'wait' => 'wait until the recommended date, because the prices are falling and the tank allows it.',
        'refuel_abroad' => 'refuel in the given country, because it saves money even with the detour.',
        'anytime' => 'no moment or place stands out.',
    ];

    /**
     * @param  string  $home  The country the vehicle is registered in.
     * @param  array<int, string>  $abroad  The countries worth the detour from where the driver lives.
     * @param  array<int, string>  $sources  Pages to read for news.
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     */
    public function __construct(
        private readonly string $language,
        private readonly string $home,
        private readonly array $abroad = [],
        private readonly bool $search = false,
        private readonly array $sources = [],
        private readonly ?string $key = null,
    ) {
        //
    }

    /**
     * With a key of its own, a provider of its own: the keys are tried one by
     * one, see FuelAdviceService.
     */
    public function provider(): Provider|string
    {
        $name = config('fuel_advice.provider');

        return $this->key === null
            ? $name
            : Ai::build([...config('ai.providers.'.$name, []), 'key' => $this->key]);
    }

    public function model(): string
    {
        return config('fuel_advice.model');
    }

    public function timeout(): int
    {
        return config('fuel_advice.timeout');
    }

    public function instructions(): string
    {
        $web = match (true) {
            $this->search => 'Use Google Search, once or twice, for '.self::NEWS.'. Only mention what you actually found and what matters for the coming two weeks.',
            $this->sources !== [] => 'Read the pages listed with the facts for '.self::NEWS.'. Only mention what you actually found and what matters for the coming two weeks.',
            default => 'You cannot look anything up: base the advice on the facts alone and do not guess at news.',
        };

        $abroad = $this->abroad === []
            ? 'Refueling abroad is not worth the detour for this driver (or where the driver lives is unknown): base the '
                .'action, the country and the date on the home country. You may mention a cheaper country only as an '
                .'option for when the driver happens to be there anyway.'
            : 'Refueling in '.implode(' or ', $this->abroad).' is worth the detour from where the driver lives: the net '
                .'savings (the savings on a full tank minus the fuel to get there and back) are positive. Weigh those net '
                .'savings against the trouble of the trip; small net savings are not worth a special trip.';

        $superPlus = 'When super_plus_due is true, the next refueling has to be Super Plus (the owner fills up with it '
            .'once every four refuelings, to keep the engine clean); the prices in the facts are already those of Super '
            .'Plus. Say so in the advice.';

        $actions = implode("\n", array_map(fn (string $action) => '- '.$action.': '.self::ACTIONS[$action], $this->actions()));

        return <<<TEXT
            You advise the driver of a vehicle on when and where to refuel, to pay as little as possible.

            You get the facts as JSON: the expected date of the next refueling and the fuel prices per country, with
            their trend. Every number in the advice comes from the facts; never calculate or invent other prices or
            distances. Prices are in euro per liter. The home country is {$this->home}, where the vehicle is
            registered. The estimated tank percentage is an estimate: do not call the tank (nearly) empty above 25%.

            {$abroad}

            {$superPlus}

            own_gas_stations are the gas stations the driver refuels at, with how much less (negative) or more the
            driver paid there on average than the average price of the country that day. When one is clearly cheaper
            than average, recommend it by name for the advised refueling (in the advised country).

            {$web}

            The action is one of:
            {$actions}

            Fill in the country when the advice is about where to refuel, else an empty string. Fill in the recommended
            date (YYYY-MM-DD) when the advice names a day, else an empty string; it is never in the past and never long
            after the expected date of the next refueling.

            Write everything in {$this->language}, plainly, to the driver. The summary is one sentence of at
            most 110 characters. The advice is short Markdown: a few paragraphs or bullets, no headings, no tables,
            and no links. Give two to four reasons of one short sentence each. The confidence is low when the facts
            are thin or the prices move without a clear trend.
            TEXT;
    }

    public function tools(): iterable
    {
        return array_values(array_filter([
            $this->search ? new WebSearch : null,
            $this->sources !== [] ? new WebFetch : null,
        ]));
    }

    /**
     * Only the countries the advice can be about: the home country, and the
     * ones worth the detour. Refueling abroad is no option without the latter.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum($this->actions())->required(),
            'country' => $schema->string()->enum(['', $this->home, ...$this->abroad])->required(),
            'recommended_date' => $schema->string()->description('YYYY-MM-DD, or empty.')->required(),
            'confidence' => $schema->string()->enum(FuelAdvice::CONFIDENCES)->required(),
            'summary' => $schema->string()->required(),
            'advice' => $schema->string()->description('Markdown.')->required(),
            'reasons' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_values(array_filter(
            FuelAdvice::ACTIONS,
            fn (string $action) => $action !== 'refuel_abroad' || $this->abroad !== [],
        ));
    }

    /**
     * @return list<string> The countries the advice can be about.
     */
    public function countries(): array
    {
        return [$this->home, ...$this->abroad];
    }

    /**
     * The facts, and the pages to read: URL context only reads the pages that
     * are named in the prompt itself.
     *
     * @param  array<string, mixed>  $facts
     */
    public function question(array $facts): string
    {
        $question = "Facts:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->sources !== []) {
            $question .= "\n\nPages to read for ".self::NEWS.":\n- ".implode("\n- ", $this->sources);
        }

        return $question;
    }
}
