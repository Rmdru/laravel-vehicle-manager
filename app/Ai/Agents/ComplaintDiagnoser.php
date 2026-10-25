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
 * What a complaint of the owner may come from, when the owner asks: the
 * likely causes for this make, model and engine, whether it is safe to drive
 * on, and what to tell the garage. It cannot look anything up: the free tier
 * has no quota for Google Search.
 */
class ComplaintDiagnoser implements Agent, HasStructuredOutput
{
    use Promptable;

    public const LIKELIHOODS = ['high', 'medium', 'low'];

    public const DRIVING = ['stop', 'careful', 'fine'];

    /**
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     */
    public function __construct(
        private readonly string $language,
        private readonly ?string $key = null,
    ) {
        //
    }

    public function provider(): Provider|string
    {
        return ApiKeys::provider(config('findings.provider'), $this->key);
    }

    public function model(): string
    {
        return config('findings.model');
    }

    public function timeout(): int
    {
        return config('findings.timeout');
    }

    public function instructions(): string
    {
        return <<<TEXT
            You help the owner of a vehicle understand a complaint about it before a visit to the garage. You get the
            complaint and the facts the app knows about the vehicle as JSON: its make, model, engine, age and mileage,
            its recent maintenance, the components of its maintenance plan, and the other open findings.

            Give the causes that are most likely for this make, model, engine and age, the most likely first, at most
            four. A cause is short (such as "worn front brake pads"); the explanation says in one or two sentences why
            it fits what the owner notices, and what would tell it apart from the other causes. Base it on the facts:
            a part that was replaced recently is a less likely cause, one that is overdue a more likely one. Where you
            rely on what you know about this model or engine, say "often" or "usually"; never invent a recall or a
            figure of the vehicle. Give a price only as a rough range in euros, for a garage in the Netherlands, or
            leave it empty when you cannot tell.

            The driving advice is one of: stop (do not drive on: brakes, steering, a warning light that means stop,
            smoke, a smell of fuel, overheating), careful (drive on calmly, but have it looked at soon) or fine (safe
            to drive, have it looked at when convenient). When in doubt between two, take the more careful one.
            Electric vehicles are not maintenance free: their tires and suspension wear faster and their brakes rust
            from little use, and their high voltage system is only for a qualified garage.

            what_to_tell_the_garage is what the owner can tell or ask the garage, in two or three short sentences:
            when it happens, and what to check. self_checks are at most three safe things the owner can check
            first, without tools or with simple ones; none where that is not safe.

            Write everything in {$this->language}, plainly, to the owner, as plain text: no Markdown, no links.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'causes' => $schema->array()->items($schema->object([
                'cause' => $schema->string()->required(),
                'likelihood' => $schema->string()->enum(self::LIKELIHOODS)->required(),
                'explanation' => $schema->string()->required(),
                'cost_range' => $schema->string()->description('Such as "€ 150 - € 300", or empty.')->required(),
            ]))->required(),
            'driving' => $schema->string()->enum(self::DRIVING)->required(),
            'driving_explanation' => $schema->string()->required(),
            'what_to_tell_the_garage' => $schema->string()->required(),
            'self_checks' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function question(array $facts): string
    {
        return "Complaint and facts:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
