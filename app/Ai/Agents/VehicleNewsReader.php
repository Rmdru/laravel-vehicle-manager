<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\ApiKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\WebFetch;

/**
 * Reads the pages of motoring clubs, consumer organisations, authorities and
 * magazines (URL context: the free tier has no quota for Google Search) and
 * picks the articles the owners of the vehicles in the app can act on. The
 * app checks every address it names, and matches the articles to vehicles.
 */
class VehicleNewsReader implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * @param  list<string>  $pages  The pages to read.
     * @param  list<string>  $vehicles  The vehicles that matter, as make, model, engine, powertrain and year.
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     * @param  string|null  $model  The model to ask; null for the configured one.
     */
    public function __construct(
        private readonly string $country,
        private readonly array $pages,
        private readonly array $vehicles,
        private readonly ?string $key = null,
        private readonly ?string $model = null,
    ) {
        //
    }

    public function provider(): Provider|string
    {
        return ApiKeys::provider(config('vehicle_news.provider'), $this->key);
    }

    public function model(): string
    {
        return $this->model ?? config('vehicle_news.model');
    }

    public function timeout(): int
    {
        return config('vehicle_news.timeout');
    }

    public function tools(): iterable
    {
        return [new WebFetch];
    }

    public function instructions(): string
    {
        $kinds = implode("\n", array_map(fn (string $kind, string $meaning) => "- {$kind}: {$meaning}", array_keys(config('vehicle_news.kinds')), config('vehicle_news.kinds')));
        $powertrains = implode(', ', array_keys(trans('powertrains', [], 'en')));
        $days = config('vehicle_news.max_age_days');

        return <<<TEXT
            You read pages of motoring clubs, consumer organisations, authorities and motoring magazines for the owners
            of vehicles registered in {$this->country}. Their goal is to keep their vehicle reliable for long at low cost.
            Pick the articles on these pages (or the articles they link to) that such an owner can act on or should know,
            of one of these kinds:
            {$kinds}

            Only articles of the past {$days} days, and only what is on the pages: never make up an article, a title or an
            address. Give the address of the article itself, as it is linked on the page; the page itself only when the
            article is on it (such as a long-term test on the page of a model).
            Leave out: advice that holds for any vehicle (such as checking the tire pressure), sponsored content and
            advertisements, new models and launches, motorsport, prices of fuel, and the rules of driving abroad
            (vignettes, environmental stickers abroad, winter tire rules of other countries: the app has a trip planner
            for those).

            Per article: the kind; a summary in English of two sentences with what it means for an owner (dates, amounts,
            models and years as the article gives them); the date it was published (YYYY-MM-DD, empty when unknown); and
            the vehicles it applies to. Leave brands, models, engines and powertrains empty when it applies to all
            vehicles; otherwise name them as the article does. A powertrain is one of: {$powertrains}. built_from and
            built_until are the years of construction it applies to, 0 when it does not say.
            The vehicles of the owners, so that you know what is worth picking about a make or model:
            {$this->vehicleList()}
            Give no articles at all when nothing on the pages is worth it.
            TEXT;
    }

    private function vehicleList(): string
    {
        return $this->vehicles === [] ? '- (none in particular)' : '- '.implode("\n- ", $this->vehicles);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'articles' => $schema->array()->items($schema->object([
                'url' => $schema->string()->required(),
                'title' => $schema->string()->required(),
                'kind' => $schema->string()->enum(array_keys(config('vehicle_news.kinds')))->required(),
                'summary' => $schema->string()->required(),
                'published_on' => $schema->string()->description('YYYY-MM-DD, or empty.')->required(),
                'brands' => $schema->array()->items($schema->string())->required(),
                'models' => $schema->array()->items($schema->string())->required(),
                'engines' => $schema->array()->items($schema->string())->required(),
                'powertrains' => $schema->array()->items($schema->string())->required(),
                'built_from' => $schema->integer()->required(),
                'built_until' => $schema->integer()->required(),
            ]))->required(),
        ];
    }

    public function question(): string
    {
        return "Pages to read:\n- ".implode("\n- ", $this->pages);
    }
}
