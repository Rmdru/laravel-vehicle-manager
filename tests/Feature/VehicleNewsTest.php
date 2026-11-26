<?php

namespace Tests\Feature;

use App\Ai\Agents\VehicleAdvisor;
use App\Ai\Agents\VehicleNewsReader;
use App\Models\Vehicle;
use App\Models\VehicleNews;
use App\Services\VehicleAdviceFacts;
use App\Services\VehicleAdviceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class VehicleNewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-26 12:00:00');
        Sleep::fake();
        config(['ai.providers.gemini.key' => 'test-key']);
    }

    private function golf(array $attributes = []): Vehicle
    {
        return $this->signInWithVehicle([
            'brand' => array_search('Volkswagen', config('vehicles.brands'), true),
            'model' => 'Tiguan',
            'construction_date' => '2017-05-01',
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function article(array $overrides = []): array
    {
        return [
            'url' => 'https://www.anwb.nl/auto/nieuws/2026/november/winterbanden-test',
            'title' => 'Winterbandentest 2026',
            'kind' => 'product_test',
            'summary' => 'The ANWB tested 16 winter tires in size 205/55 R16; two scored poorly on wet roads.',
            'published_on' => '2026-11-20',
            'brands' => [],
            'models' => [],
            'engines' => [],
            'powertrains' => [],
            'built_from' => 0,
            'built_until' => 0,
            ...$overrides,
        ];
    }

    public function test_the_news_is_read_from_the_sources_and_only_what_checks_out_is_stored()
    {
        $this->golf();
        $requested = [];
        Http::swap(new Factory);
        Http::fake(function (Request $request) use (&$requested) {
            $requested[] = $request->url();

            return match (true) {
                str_contains($request->url(), 'rappelconso') => Http::response(['results' => [[
                    'marque_produit' => 'volkswagen',
                    'modeles_ou_references' => 'atlas, tiguan allspace voiture particulière.',
                    'identification_produits' => "['28.11.2016 - 22.09.2018$$$$']",
                    'motif_rappel' => 'corrosion d\'un boulon de crémaillère de direction',
                    'risques_encourus' => 'blessures',
                    'date_publication' => '2026-11-17T08:03:41+00:00',
                    'lien_vers_la_fiche_rappel' => 'https://rappel.conso.gouv.fr/fiche-rappel/50105/rapex',
                ]]]),
                str_contains($request->url(), 'made-up') => Http::response('', 404),
                default => Http::response('<html></html>'),
            };
        });
        VehicleNewsReader::fake([['articles' => [
            $this->article(),
            // Not on a site that was read, made up, or too old.
            $this->article(['url' => 'https://example.com/article']),
            $this->article(['url' => 'https://www.anwb.nl/made-up']),
            $this->article(['url' => 'https://www.anwb.nl/old', 'published_on' => '2026-01-01']),
            // Of another kind than the ones there are.
            $this->article(['url' => 'https://www.anwb.nl/launch', 'kind' => 'new_model']),
        ]]]);

        $this->artisan('import:vehicle-news')->assertExitCode(0);

        $this->assertSame(2, VehicleNews::query()->count());
        $test = VehicleNews::query()->where('kind', 'product_test')->sole();
        $this->assertSame('anwb.nl', $test->source);
        $this->assertSame(['netherlands'], $test->countries);
        $recall = VehicleNews::query()->where('kind', 'recall')->sole();
        $this->assertSame([], $recall->countries);
        $this->assertSame(['brands' => ['volkswagen'], 'models' => ['atlas', 'tiguan allspace'], 'built_from' => 2016, 'built_until' => 2018], $recall->applies_to);

        // The pages of the country, and of the model on the ADAC, as Volkswagen is "vw" there.
        VehicleNewsReader::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'https://www.anwb.nl/auto/nieuws')
            && str_contains($prompt->prompt, 'autokatalog/marken-modelle/vw/tiguan/')
            && str_contains($prompt->agent->instructions(), 'Volkswagen Tiguan'));
        // Nothing of the owner in what is asked of the sites.
        $this->assertEmpty(array_filter($requested, fn (string $url) => str_contains($url, '@')));

        // Read again: nothing twice.
        VehicleNewsReader::fake([['articles' => [$this->article()]]]);
        $this->artisan('import:vehicle-news');
        $this->assertSame(2, VehicleNews::query()->count());
    }

    public function test_only_the_news_that_applies_to_the_vehicle_is_in_its_facts()
    {
        $vehicle = $this->golf();
        $news = fn (array $attributes) => VehicleNews::create([
            'url_hash' => VehicleNews::hash($attributes['url']),
            'source' => 'anwb.nl',
            'title' => 'Article',
            'summary' => 'Summary',
            'kind' => 'known_issue',
            'countries' => ['netherlands'],
            'applies_to' => [],
            ...$attributes,
        ]);
        $general = $news(['url' => 'https://www.anwb.nl/a']);
        $tiguan = $news(['url' => 'https://www.anwb.nl/b', 'applies_to' => ['brands' => ['Volkswagen'], 'models' => ['Tiguan Allspace'], 'built_from' => 2016, 'built_until' => 2018]]);
        $news(['url' => 'https://www.anwb.nl/c', 'applies_to' => ['brands' => ['Opel']]]);
        $news(['url' => 'https://www.anwb.nl/d', 'applies_to' => ['powertrains' => ['electricity']]]);
        $news(['url' => 'https://www.anwb.nl/e', 'countries' => ['germany']]);
        $news(['url' => 'https://www.anwb.nl/f', 'applies_to' => ['brands' => ['Volkswagen'], 'built_until' => 2012]]);

        $ids = array_column(app(VehicleAdviceFacts::class)->for($vehicle->fresh())['news'], 'id');

        $this->assertEqualsCanonicalizing(["news:{$general->id}", "news:{$tiguan->id}"], $ids);
    }

    public function test_a_tip_about_the_news_tells_where_it_comes_from_and_there_are_only_so_many()
    {
        $vehicle = $this->golf();
        $articles = collect(range(1, 3))->map(fn (int $number) => VehicleNews::create([
            'url_hash' => VehicleNews::hash("https://www.anwb.nl/{$number}"),
            'url' => "https://www.anwb.nl/{$number}",
            'source' => 'anwb.nl',
            'title' => "Article {$number}",
            'summary' => 'Summary',
            'kind' => 'known_issue',
            'published_on' => '2026-11-20',
            'countries' => [],
            'applies_to' => [],
        ]));
        $tip = fn (string $key, string $subject, string $newsId) => [
            'key' => $key, 'subject' => $subject, 'category' => 'known_issue', 'priority' => 'medium', 'basis' => 'general_knowledge',
            'action' => 'none', 'due_date' => '', 'title' => $key, 'body' => 'Volgens de ANWB …', 'returns_because' => '', 'news_id' => $newsId,
        ];
        VehicleAdvisor::fake([['suggestions' => [
            $tip('dsg_issue', 'gearbox_oil', "news:{$articles[0]->id}"),
            $tip('made_up_article', 'other', 'news:999'),
            $tip('steering_recall', 'suspension', "news:{$articles[1]->id}"),
            $tip('third_article', 'brakes', "news:{$articles[2]->id}"),
        ]]]);

        $suggestions = app(VehicleAdviceService::class)->generate($vehicle->fresh(), 'en')->suggestions;

        // A made-up article is no source; of the news only two tips.
        $this->assertSame(['dsg_issue', 'made_up_article', 'steering_recall'], array_column($suggestions, 'key'));
        $this->assertNull($suggestions[1]['source']);
        VehicleAdvisor::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, "\"id\":\"news:{$articles[0]->id}\""));

        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tips.advice.suggestions.0.source', ['url' => 'https://www.anwb.nl/1', 'name' => 'anwb.nl', 'date' => '20 November 2026']));

        // Kept, with where it comes from, after the article is gone.
        $this->post(route('vehicle-advice.status'), ['key' => 'dsg_issue', 'status' => 'kept']);
        VehicleNews::query()->delete();
        $this->get(route('tips'))
            ->assertInertia(fn (Assert $page) => $page->where('tips.kept.0.source.url', 'https://www.anwb.nl/1'));
    }

    public function test_the_news_is_read_twice_a_week_before_the_tips()
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'import:vehicle-news'));

        $this->assertSame('30 1 * * 1,4', $event?->expression);
        $this->assertTrue($event?->onOneServer);
    }
}
