<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\VehicleNewsReader;
use App\Ai\ApiKeys;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Models\VehicleNews;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * The news the tips can be about: read twice a week from the sources of
 * config/vehicle_news.php, per country rather than per owner, and matched to
 * a vehicle by the app (see VehicleNews::appliesTo()).
 */
class VehicleNewsService
{
    /** Generic, so that nothing tells who uses the app. */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; VehicleManager/1.0)';

    /** Seconds to wait for a page that is checked. */
    private const CHECK_TIMEOUT = 10;

    private bool $asked = false;

    /**
     * The news of the vehicle, for its facts: the most recent first.
     *
     * @return list<array{id: string, date: string, source: string, kind: string, title: string, summary: string}>
     */
    public function facts(Vehicle $vehicle): array
    {
        return VehicleNews::query()
            ->where('created_at', '>=', now()->subDays(config('vehicle_news.max_age_days')))
            ->latest()
            ->get()
            ->filter(fn (VehicleNews $news) => $news->appliesTo($vehicle))
            ->take(config('vehicle_news.per_vehicle'))
            ->map(fn (VehicleNews $news) => [
                'id' => 'news:'.$news->id,
                'date' => ($news->published_on ?? $news->created_at)->toDateString(),
                'source' => $news->source,
                'kind' => $news->kind,
                'title' => $news->title,
                'summary' => $news->summary,
            ])
            ->values()
            ->all();
    }

    /**
     * Reads the news for the vehicles in use: their countries, makes and
     * models. The number of articles stored.
     */
    public function import(): int
    {
        $vehicles = Vehicle::query()
            ->where('status', VehicleStatus::Drivable->value)
            ->whereHas('user', fn ($query) => $query->recentlyLoggedIn())
            ->get();

        $stored = $this->recalls($vehicles);

        foreach ($vehicles->groupBy('country_registration') as $country => $inCountry) {
            $pages = [
                ...array_column((array) config("vehicle_news.sources.{$country}", []), 'url'),
                ...$this->modelPages($inCountry),
            ];

            foreach (array_chunk(array_values(array_unique($pages)), config('vehicle_news.pages_per_request')) as $chunk) {
                $stored += $this->read((string) $country, $chunk, $inCountry);
            }
        }

        return $stored;
    }

    /**
     * The pages per model that answer: checked once in a while, as an address
     * made from a make and a model may not exist.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     * @return list<string>
     */
    public function modelPages(Collection $vehicles): array
    {
        $pages = [];

        foreach (config('vehicle_news.model_pages') as $page) {
            foreach ($vehicles as $vehicle) {
                $brand = Str::slug((string) (config('vehicles.brands')[$vehicle->brand] ?? $vehicle->brand));
                $model = Str::slug(Str::before(trim((string) $vehicle->model), ' '));

                if ($brand === '' || $model === '') {
                    continue;
                }

                $url = str_replace(['{brand}', '{model}'], [$page['brands'][$brand] ?? $brand, $model], $page['url']);

                if (Cache::remember('vehicle-news:page:'.md5($url), now()->addDays(config('vehicle_news.model_page_checked_for_days')), fn () => $this->answers($url))) {
                    $pages[] = $url;
                }
            }
        }

        return array_values(array_unique($pages));
    }

    /**
     * Asks the model to read the pages, with every key and then the fallback
     * model; stores what checks out. Nothing when no key gives an answer.
     *
     * @param  list<string>  $pages
     * @param  Collection<int, Vehicle>  $vehicles
     */
    private function read(string $country, array $pages, Collection $vehicles): int
    {
        $descriptions = $vehicles
            ->map(fn (Vehicle $vehicle) => trim(implode(' ', array_filter([
                config('vehicles.brands')[$vehicle->brand] ?? $vehicle->brand,
                $vehicle->model,
                $vehicle->engine,
                $vehicle->powertrain,
                $vehicle->construction_date?->format('Y'),
            ]))))
            ->unique()
            ->values()
            ->all();

        try {
            $response = $this->ask(Str::headline($country), $pages, $descriptions);
        } catch (Throwable $exception) {
            Log::warning('The vehicle news could not be read', ['country' => $country, 'message' => $exception->getMessage()]);

            return 0;
        }

        $hosts = array_map(fn (string $page) => self::host($page), $pages);
        $stored = 0;

        foreach ((array) ($response['articles'] ?? []) as $article) {
            if (is_array($article) && $this->store($country, $article, $hosts)) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * @param  list<string>  $pages
     * @param  list<string>  $vehicles
     *
     * @throws Throwable The last failure, when no key gave an answer.
     */
    private function ask(string $country, array $pages, array $vehicles): StructuredAgentResponse
    {
        $failure = new RuntimeException('No API key for the vehicle news.');
        $models = array_values(array_unique(array_filter([config('vehicle_news.model'), config('vehicle_news.fallback_model')])));

        foreach ($models as $model) {
            foreach (ApiKeys::shuffled(config('vehicle_news.provider')) as $number => $key) {
                // The free tier allows only so many requests per minute.
                if ($this->asked) {
                    Sleep::for(config('vehicle_news.pause_between'))->seconds();
                }

                $this->asked = true;
                $reader = new VehicleNewsReader($country, $pages, $vehicles, $key, $model);

                try {
                    $response = $reader->prompt($reader->question());

                    if (! $response instanceof StructuredAgentResponse) {
                        throw new RuntimeException('The news reader gave no structured answer.');
                    }

                    return $response;
                } catch (Throwable $exception) {
                    $failure = $exception;

                    Log::warning('Vehicle news failed, trying the next key or model', [
                        'model' => $model,
                        'key' => $key === null ? null : $number + 1,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        throw $failure;
    }

    /**
     * Stores an article when it checks out: on one of the sites that were
     * read, recent, new, and its address answers (the model may make one up).
     *
     * @param  array<string, mixed>  $article
     * @param  list<string>  $hosts
     */
    private function store(string $country, array $article, array $hosts): bool
    {
        $url = trim((string) ($article['url'] ?? ''));
        $title = Str::limit(trim((string) ($article['title'] ?? '')), 250);
        $summary = trim((string) ($article['summary'] ?? ''));
        $kind = (string) ($article['kind'] ?? '');
        $published = $this->date((string) ($article['published_on'] ?? ''));

        if (! Str::startsWith($url, ['https://', 'http://'])
            || ! in_array(self::host($url), $hosts, true)
            || $title === '' || $summary === ''
            || ! array_key_exists($kind, config('vehicle_news.kinds'))
            || ($published !== null && $published->lt(today()->subDays(config('vehicle_news.max_age_days'))))
            || VehicleNews::query()->where('url_hash', VehicleNews::hash($url))->exists()
            || ! $this->answers($url)) {
            return false;
        }

        $list = fn (string $field) => array_values(array_filter(array_map(fn ($value) => Str::limit(trim((string) $value), 60, ''), (array) ($article[$field] ?? []))));
        $powertrains = array_keys(trans('powertrains', [], 'en'));

        VehicleNews::create([
            'url_hash' => VehicleNews::hash($url),
            'url' => $url,
            'source' => self::host($url),
            'title' => $title,
            'summary' => Str::limit($summary, 600),
            'kind' => $kind,
            'published_on' => $published,
            'countries' => [$country],
            'applies_to' => array_filter([
                'brands' => $list('brands'),
                'models' => $list('models'),
                'engines' => $list('engines'),
                'powertrains' => array_values(array_intersect($list('powertrains'), $powertrains)),
                'built_from' => (int) ($article['built_from'] ?? 0) ?: null,
                'built_until' => (int) ($article['built_until'] ?? 0) ?: null,
            ]),
        ]);

        return true;
    }

    /**
     * The recalls of cars of the makes in use, in the EU: open data, so no
     * model has to read them. Matched to a vehicle by make, model and the
     * years it was built, as the recall gives them.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     */
    private function recalls(Collection $vehicles): int
    {
        $brands = $vehicles->map(fn (Vehicle $vehicle) => Str::lower((string) (config('vehicles.brands')[$vehicle->brand] ?? $vehicle->brand)))->filter()->unique();
        $since = today()->subDays(config('vehicle_news.max_age_days'))->toDateString();
        $stored = 0;

        foreach ($brands as $brand) {
            try {
                $records = Http::withUserAgent(self::USER_AGENT)
                    ->timeout(30)
                    ->get(config('vehicle_news.recalls.url'), [
                        'where' => sprintf('sous_categorie_produit="%s" and marque_produit="%s" and date_publication>="%s"', config('vehicle_news.recalls.subcategory'), addslashes($brand), $since),
                        'order_by' => 'date_publication desc',
                        'limit' => config('vehicle_news.recalls.per_brand'),
                    ])
                    ->throw()
                    ->json('results', []);
            } catch (Throwable $exception) {
                Log::warning('The recalls could not be imported', ['brand' => $brand, 'message' => $exception->getMessage()]);

                continue;
            }

            foreach ((array) $records as $record) {
                $url = (string) ($record['lien_vers_la_fiche_rappel'] ?? '');

                if ($url === '' || VehicleNews::query()->where('url_hash', VehicleNews::hash($url))->exists()) {
                    continue;
                }

                // Such as "atlas, tiguan allspace voiture particulière.": the models, without what kind of vehicle.
                $models = collect(explode(',', (string) ($record['modeles_ou_references'] ?? '')))
                    ->map(fn (string $model) => trim((string) preg_replace('/\s+(voiture|véhicule|fourgon|camionnette|utilitaire|équipée?).*$/u', '', trim($model)), " .\t"))
                    ->filter()
                    ->values()
                    ->all();
                // Such as "['13.07.2022 - 06.06.2025$$$$']": when the recalled vehicles were built.
                preg_match_all('/\d{2}\.\d{2}\.(\d{4})/', (string) ($record['identification_produits'] ?? ''), $years);

                VehicleNews::create([
                    'url_hash' => VehicleNews::hash($url),
                    'url' => $url,
                    'source' => self::host($url),
                    'title' => Str::limit('Recall '.Str::title($brand).' '.implode(', ', $models), 250),
                    'summary' => Str::limit(trim('In French: '.($record['motif_rappel'] ?? '').' Risk: '.($record['risques_encourus'] ?? '')), 600),
                    'kind' => 'recall',
                    'published_on' => $this->date(substr((string) ($record['date_publication'] ?? ''), 0, 10)),
                    // A recall of the EU: in every country.
                    'countries' => [],
                    'applies_to' => array_filter([
                        'brands' => [$brand],
                        'models' => $models,
                        'built_from' => $years[1] === [] ? null : (int) min($years[1]),
                        'built_until' => $years[1] === [] ? null : (int) max($years[1]),
                    ]),
                ]);

                $stored++;
            }
        }

        return $stored;
    }

    /**
     * Whether an address answers: a page that exists, not an error.
     */
    private function answers(string $url): bool
    {
        try {
            return Http::withUserAgent(self::USER_AGENT)->timeout(self::CHECK_TIMEOUT)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay()->min(today()), null, false);
    }

    private static function host(string $url): string
    {
        return Str::after(Str::lower((string) parse_url($url, PHP_URL_HOST)), 'www.');
    }
}
