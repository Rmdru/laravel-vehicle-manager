<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\FuelAdvisor;
use App\Ai\ApiKeys;
use App\Models\FuelAdvice;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Support\StatusNotification;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Generates the fuel advice of a vehicle with an AI model, and prepares it
 * for the dashboard.
 */
class FuelAdviceService
{
    /** The language the advice is written in, by locale: addressed as the interface does. */
    private const LANGUAGES = [
        'en' => 'English',
        'nl' => 'Dutch, addressing the driver informally ("je")',
        'de' => 'German, addressing the driver formally ("Sie")',
        'fr' => 'French, addressing the driver formally ("vous")',
        'it' => 'Italian, addressing the driver informally ("tu")',
        'es' => 'Spanish, addressing the driver informally ("tú")',
    ];

    /** The icon of each action, as on the dashboard (see FuelAdvice.vue). */
    private const ICONS = [
        'refuel_now' => 'ic:round-local-gas-station',
        'wait' => 'mdi:timer-sand',
        'refuel_abroad' => 'mdi:earth-arrow-right',
        'anytime' => 'ic:round-check',
    ];

    /** Days after an advice a refueling still says something about it, at most. */
    private const REVIEW_DAYS = 30;

    /** Sources shown with the advice, at most. */
    private const SOURCES = 6;

    public function __construct(private readonly FuelAdviceFacts $facts)
    {
        //
    }

    /**
     * Null when there is nothing to advise on.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function generate(Vehicle $vehicle, string $locale): ?FuelAdvice
    {
        $facts = $this->facts->for($vehicle);

        if ($facts === null) {
            return null;
        }

        $abroad = collect((array) $facts['countries'])->where('worth_the_detour', true)->pluck('country')->map(fn ($country) => (string) $country)->values()->all();
        $advisor = fn (bool $search = false, array $sources = [], ?string $key = null) => new FuelAdvisor(
            self::LANGUAGES[$locale] ?? 'English', $facts['home_country'], $abroad, $search, $sources, $key, (bool) ($facts['old_fuel'] ?? false),
        );

        [$response, $grounded] = $this->ask($facts, $advisor);

        return $vehicle->fuelAdvices()->create([
            'locale' => $locale,
            'action' => in_array($response['action'] ?? null, $advisor()->actions(), true) ? $response['action'] : 'anytime',
            'country' => in_array($response['country'] ?? null, $advisor()->countries(), true) ? $response['country'] : null,
            'recommended_date' => $this->date((string) ($response['recommended_date'] ?? '')),
            'confidence' => in_array($response['confidence'] ?? null, FuelAdvice::CONFIDENCES, true) ? $response['confidence'] : 'low',
            'summary' => Str::limit(trim((string) ($response['summary'] ?? '')), 200),
            'body' => trim((string) ($response['advice'] ?? '')),
            'reasons' => array_values(array_filter(array_map('trim', (array) ($response['reasons'] ?? [])))),
            'sources' => $this->sources($response),
            'facts' => $facts,
            'model' => $response->meta->model ?? config('fuel_advice.model'),
            'grounded' => $grounded,
        ]);
    }

    /**
     * Asks with every API key, in random order, before asking with less of the
     * web: Google Search and the pages to read have limits of their own on the
     * free tier, and an advice on the prices alone is better than none.
     *
     * @param  array<string, mixed>  $facts
     * @param  Closure(bool, list<string>, string|null): FuelAdvisor  $advisor  Makes the advisor, with Google Search, the pages to read and the key.
     * @return array{0: StructuredAgentResponse, 1: bool} The answer, and whether the web was used.
     *
     * @throws Throwable The last failure, when no attempt succeeded.
     */
    private function ask(array $facts, Closure $advisor): array
    {
        $sources = array_values(array_map('strval', (array) config('fuel_advice.sources', [])));
        $keys = ApiKeys::shuffled(config('fuel_advice.provider'));
        $failure = null;

        $levels = array_filter([
            config('fuel_advice.search') ? [true, $sources] : null,
            $sources !== [] ? [false, $sources] : null,
            [false, []],
        ]);

        foreach ($levels as [$search, $withSources]) {
            foreach ($keys as $number => $key) {
                try {
                    return [$this->answer($advisor($search, $withSources, $key), $facts), $search || $withSources !== []];
                } catch (Throwable $exception) {
                    $failure = $exception;

                    // A refused key is of no use for the next attempts either.
                    if (ApiKeys::refused($exception)) {
                        unset($keys[$number]);
                    }

                    Log::warning('Fuel advice failed, trying the next key or less of the web', [
                        'key' => $key === null ? null : $number + 1,
                        'search' => $search,
                        'sources' => count($withSources),
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            if ($keys === []) {
                break;
            }
        }

        throw $failure;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function answer(FuelAdvisor $advisor, array $facts): StructuredAgentResponse
    {
        $response = $advisor->prompt($advisor->question($facts));

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('The fuel advisor gave no structured answer.');
        }

        return $response;
    }

    /**
     * The pages the advice was based on: the ones Google Search cited, and the
     * ones read with URL context. A structured answer carries no citations of
     * the latter, so they come from the results of reading them.
     *
     * @return array<int, array{url: string, title: string}>
     */
    private function sources(StructuredAgentResponse $response): array
    {
        $read = [];

        foreach ($response->steps as $step) {
            foreach ($step->providerToolCalls as $call) {
                if (($call->data['type'] ?? null) !== 'url_context_result') {
                    continue;
                }

                foreach ((array) ($call->data['result'] ?? []) as $result) {
                    if (($result['status'] ?? null) === 'success' && filled($result['url'] ?? null)) {
                        $read[] = new UrlCitation((string) $result['url']);
                    }
                }
            }
        }

        return $response->meta->citations
            ->filter(fn ($citation) => $citation instanceof UrlCitation && filled($citation->url))
            ->concat($read)
            ->unique(fn (UrlCitation $citation) => rtrim($citation->url, '/'))
            ->take(self::SOURCES)
            ->map(fn (UrlCitation $citation) => [
                'url' => $citation->url,
                'title' => $citation->title ?: Str::after((string) parse_url($citation->url, PHP_URL_HOST), 'www.'),
            ])
            ->values()
            ->all();
    }

    /**
     * The recommended day, never in the past. Null when the model named none.
     */
    private function date(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return rescue(fn () => Carbon::createFromFormat('Y-m-d', $value)->startOfDay()->max(today()), null, false);
    }

    public function latest(Vehicle $vehicle, string $locale): ?FuelAdvice
    {
        return $vehicle->fuelAdvices()->where('locale', $locale)->latest()->first();
    }

    /**
     * Whether the advice can be generated by hand: not again within minutes.
     */
    public function canGenerate(Vehicle $vehicle, string $locale): bool
    {
        return ! $vehicle->fuelAdvices()
            ->where('locale', $locale)
            ->where('created_at', '>', now()->subMinutes(config('fuel_advice.regenerate_after')))
            ->exists();
    }

    /**
     * The advice in the current locale, for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function forDashboard(Vehicle $vehicle): array
    {
        $locale = app()->getLocale();
        $advice = $this->latest($vehicle, $locale);

        return [
            'advice' => $advice ? $this->present($advice) : null,
            'canGenerate' => $this->canGenerate($vehicle, $locale),
            'generateUrl' => route('fuel-advice.store'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FuelAdvice $advice): array
    {
        $countries = config('countries');
        $facts = $advice->facts;
        $prices = collect((array) ($facts['countries'] ?? []))->keyBy('country');
        $home = $prices->get($facts['home_country'] ?? '');
        // The country abroad the advice is about, or else the cheapest one worth the detour.
        $country = $prices->get((string) $advice->country);
        $country = ($country['worth_the_detour'] ?? false) ? $country : $prices->firstWhere('worth_the_detour', true);
        $next = $facts['next_refueling'] ?? [];
        $money = fn (float $value, int $digits = 3) => '€ '.number_format($value, $digits, ',', '.');
        $day = fn (?string $date) => $date ? Carbon::parse($date)->isoFormat('dddd D MMMM') : null;

        return [
            'id' => $advice->id,
            'action' => $advice->action,
            'summary' => $advice->summary,
            'body' => $advice->body,
            'reasons' => $advice->reasons,
            'confidence' => $advice->confidence,
            'country' => $advice->country ? [
                'name' => $countries[$advice->country]['name'] ?? $advice->country,
                'isoCode' => $countries[$advice->country]['iso_code'] ?? null,
            ] : null,
            'recommendedDate' => $day($advice->recommended_date?->toDateString()),
            'expectedRefuelDate' => $day($next['expected_date'] ?? null),
            'tankPercentage' => $next['estimated_tank_percentage_now'] ?? null,
            'fuelType' => trans('fuel_types')[$facts['fuel_type'] ?? ''] ?? ($facts['fuel_type'] ?? null),
            'superPlusDue' => (bool) ($facts['super_plus_due'] ?? false),
            'homePrice' => $home ? [
                'name' => $countries[$home['country']]['name'] ?? $home['country'],
                'isoCode' => $countries[$home['country']]['iso_code'] ?? null,
                'price' => $money((float) $home['latest_price']).'/l',
            ] : null,
            'bestPrice' => $country ? [
                'name' => $countries[$country['country']]['name'] ?? $country['country'],
                'isoCode' => $countries[$country['country']]['iso_code'] ?? null,
                'price' => $money((float) $country['latest_price']).'/l',
                'distance' => '± '.$country['distance_from_driver_km'].' km',
                'savings' => $money((float) $country['net_savings_per_full_tank_eur'], 2),
            ] : null,
            'cheapestStation' => ($station = $this->cheapestStation($facts)) ? [
                'name' => $station['name'],
                'difference' => $money(abs((float) $station['average_difference_with_country_average'])).'/l',
            ] : null,
            'review' => $this->review($advice),
            'sources' => $advice->sources,
            'model' => $advice->model,
            'grounded' => $advice->grounded,
            'generatedAt' => $advice->created_at->isoFormat('D MMMM HH:mm'),
            'isCurrent' => $advice->created_at->isToday(),
        ];
    }

    /**
     * The gas station the driver pays least at compared to the average of the
     * country, of those in the country the advice is about (or at home).
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>|null
     */
    private function cheapestStation(array $facts): ?array
    {
        return collect((array) ($facts['own_gas_stations'] ?? []))
            ->filter(fn (array $station) => $station['average_difference_with_country_average'] < 0)
            ->first();
    }

    /**
     * How the advice before the latest refueling turned out: whether it was
     * followed, and what the refueling cost compared to the average price of
     * the country at the time of the advice. Null without such an advice, or
     * when the refueling came long after it.
     *
     * @return array<string, mixed>|null
     */
    private function review(FuelAdvice $current): ?array
    {
        $refueling = Refueling::query()->where('vehicle_id', $current->vehicle_id)->newest()->first();

        if ($refueling === null) {
            return null;
        }

        $date = Carbon::parse($refueling->date);

        $advice = FuelAdvice::query()
            ->where('vehicle_id', $current->vehicle_id)
            ->where('created_at', '<', $refueling->created_at)
            ->whereDate('created_at', '<=', $date)
            ->whereDate('created_at', '>=', $date->copy()->subDays(self::REVIEW_DAYS))
            ->latest()
            ->first();

        $country = collect((array) ($advice?->facts['countries'] ?? []))->firstWhere('country', $refueling->country);

        if ($advice === null || $country === null
            || ! (FuelAdviceFacts::comparable($refueling->fuel_type, $country['fuel_type'] ?? null)
                || FuelAdviceFacts::comparable($country['fuel_type'] ?? null, $refueling->fuel_type))) {
            return null;
        }

        $countries = config('countries');
        $money = fn (float $value, int $digits = 3) => '€ '.number_format($value, $digits, ',', '.');
        $difference = (float) $country['latest_price'] - (float) $refueling->unit_price;

        return [
            'adviceDate' => $advice->created_at->isoFormat('D MMMM'),
            'summary' => $advice->summary,
            'followed' => match ($advice->action) {
                'refuel_now' => $date->lte($advice->created_at->copy()->addDay()->startOfDay()),
                'wait' => $date->gte($advice->recommended_date ?? $advice->created_at->copy()->addDay()->startOfDay()),
                'refuel_abroad' => $refueling->country === $advice->country,
                default => null,
            },
            'refuelDate' => $date->isoFormat('dddd D MMMM'),
            'country' => $countries[$refueling->country]['name'] ?? $refueling->country,
            'paid' => $money((float) $refueling->unit_price).'/l',
            'average' => $money((float) $country['latest_price']).'/l',
            'difference' => $money(abs($difference)).'/l',
            'cheaper' => $difference >= 0,
            'savings' => $money(abs($difference * (float) $refueling->amount), 2),
        ];
    }

    /**
     * A new refueling changes when the next one is due and what is in the
     * tank: the advice is generated again, after the response, in the language
     * of the user who entered it. Not for an older refueling entered later.
     */
    public function regenerateAfter(Refueling $refueling): void
    {
        $vehicle = $refueling->vehicle;
        $locale = app()->getLocale();

        if (! $refueling->wasRecentlyCreated
            || str_starts_with((string) $refueling->fuel_type, 'Electricity')
            || in_array($vehicle->powertrain, ['electricity', 'hydrogen'], true)
            || ! Refueling::query()->where('vehicle_id', $vehicle->id)->newest()->first()?->is($refueling)) {
            return;
        }

        defer(fn () => Cache::lock('fuel-advice:'.$vehicle->id, 120)->get(function () use ($vehicle, $locale) {
            try {
                $this->generate($vehicle, $locale);
            } catch (Throwable $exception) {
                Log::warning('Fuel advice could not be generated after a refueling', [
                    'vehicle' => $vehicle->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }));
    }

    /**
     * The advice as a notification on top of "Needs attention", when it calls
     * for refueling now with certainty, or names today or tomorrow.
     *
     * @return array<string, mixed>|null
     */
    public function notification(Vehicle $vehicle): ?array
    {
        $advice = $this->latest($vehicle, app()->getLocale());

        if ($advice === null || ! $advice->created_at->isToday()) {
            return null;
        }

        $urgent = $advice->action === 'refuel_now' && $advice->confidence === 'high';
        $soon = $advice->action !== 'anytime' && $advice->recommended_date?->lte(today()->addDay());

        if (! $urgent && ! $soon) {
            return null;
        }

        $type = $urgent ? 'warning' : 'info';
        $types = StatusNotification::types();

        return [
            ...$types[$type],
            'key' => 'fuel_advice',
            'type' => $type,
            'text' => $advice->summary,
            'icon' => self::ICONS[$advice->action] ?? self::ICONS['anytime'],
            'typeIcon' => $types[$type]['icon'],
            // On top, before the critical ones.
            'priority' => -1,
            'hasModal' => false,
            'data' => [],
            'opensFuelAdvice' => true,
        ];
    }
}
