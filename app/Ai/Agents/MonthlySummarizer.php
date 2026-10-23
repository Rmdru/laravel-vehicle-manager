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
 * Sums up a month of a vehicle in a few calm sentences: what stood out, and
 * whether anything did at all. The app calculates every figure and shows them
 * next to the text; the model only puts them into words.
 */
class MonthlySummarizer implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     */
    public function __construct(
        private readonly string $language,
        private readonly ?string $key = null,
    ) {
        //
    }

    /**
     * With a key of its own, a provider of its own: the keys are tried one by
     * one, see MonthlySummaryService.
     */
    public function provider(): Provider|string
    {
        return ApiKeys::provider(config('monthly_summary.provider'), $this->key);
    }

    public function model(): string
    {
        return config('monthly_summary.model');
    }

    public function timeout(): int
    {
        return config('monthly_summary.timeout');
    }

    public function instructions(): string
    {
        $asUsual = config('monthly_summary.as_usual_within');

        return <<<TEXT
            You sum up a month of a vehicle for its owner, at the top of the dashboard of the app. You get the facts the
            app calculated as JSON. The figures are shown next to your text already: your text says what they mean.

            period.kind is previous_month for a look back at the month that just ended, or month_to_date for the
            current month so far (period.days of period.days_in_month). The expected values are the averages of the
            year before, scaled to the days of the period: compare with those, not with a full month.

            distance.km is the distance driven; when distance.estimated is true, it is an estimate: say "about".
            costs.running_eur
            are the costs of driving (fuel, parking, toll, ferries), costs.fixed_eur those that are the same every
            month (insurance, road tax), costs.incidental_eur those that only come now and then (maintenance, washing,
            a fine, a vignette), by type. Explain a high total by its incidental costs rather than calling the month
            expensive. What the refuelings of a month cost depends on when they happen to fall: compare the fuel by
            costs.fuel_per_km_eur against costs.average_fuel_per_km_eur, never by its total. Compare the total with
            costs.average_month_total_eur only for a previous_month, a full month. consumption is the average consumption of the refuelings of the period, against the year
            before. upcoming are the deadlines in the coming weeks, components of the maintenance plan included; they are listed below your text already, so do not
            repeat them. Only mention one when it adds something they do not say (such as an insurance that ends: a
            good moment to compare).

            Say what stood out, the most notable first. A difference of less than {$asUsual}% is as usual and not
            worth mentioning. When nothing stands out, say so plainly and briefly, such as that it was a quiet month
            in which everything went as usual: never make something small sound important, and never give generic
            advice. Use only figures from the facts, rounded (whole kilometers, whole euros); never calculate new
            ones beyond a rounded percentage, and never invent any. Where a fact is missing, leave the subject out.
            Write the figures as is usual in the language, with its separator of thousands and decimals.

            Write in {$this->language}, plainly and calmly, to the owner. The headline says the main point in at most
            60 characters (such as "More kilometers than usual" or "A quiet month"), not merely what it is about.
            The text is one to three short sentences, at most 60 words, of plain text: no Markdown, no lists, no links.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()->required(),
            'text' => $schema->string()->required(),
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
