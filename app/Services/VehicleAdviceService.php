<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\VehicleAdvisor;
use App\Ai\ApiKeys;
use App\Models\Finding;
use App\Models\Vehicle;
use App\Models\VehicleAdvice;
use App\Models\VehicleNews;
use App\Models\VehicleTip;
use App\Support\StatusNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Generates the general advice of a vehicle with an AI model: suggestions
 * based on everything that is known about it, and prepares them for the
 * dashboard.
 */
class VehicleAdviceService
{
    /** The language the advice is written in, by locale: addressed as the interface does. */
    public const LANGUAGES = [
        'en' => 'English',
        'nl' => 'Dutch, addressing the owner informally ("je")',
        'de' => 'German, addressing the owner formally ("Sie")',
        'fr' => 'French, addressing the owner formally ("vous")',
        'it' => 'Italian, addressing the owner informally ("tu")',
        'es' => 'Spanish, addressing the owner informally ("tú")',
    ];

    /** The icon of each category: for a tip without an icon of its own. */
    private const ICONS = [
        'maintenance' => 'mdi:car-wrench',
        'season' => 'mdi:snowflake',
        'known_issue' => 'mdi:alert-decagram-outline',
        'costs' => 'mdi:hand-coin-outline',
        'value' => 'mdi:tag-outline',
        'legal' => 'ic:baseline-security',
        'usage' => 'ic:baseline-route',
    ];

    /** @var array<string, list<string>> The keys of the active components of the plan, by vehicle. */
    private array $activeComponents = [];

    public function __construct(private readonly VehicleAdviceFacts $facts)
    {
        //
    }

    /**
     * Null when the advice of the vehicle is being generated already.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function generate(Vehicle $vehicle, string $locale): ?VehicleAdvice
    {
        return $this->locked($vehicle, $locale, fn () => $this->generateFrom($vehicle, $locale, $this->facts->for($vehicle)));
    }

    /**
     * The advice is generated again by the daily run when the facts changed,
     * or when it is a week old: the season, and what it calls for, change too.
     * Null when it is still up to date, or being generated already.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function refresh(Vehicle $vehicle, string $locale): ?VehicleAdvice
    {
        return $this->locked($vehicle, $locale, function () use ($vehicle, $locale) {
            $facts = $this->facts->for($vehicle);
            $latest = $this->latest($vehicle, $locale);

            if ($latest !== null
                && $latest->facts_hash === $this->facts->fingerprint($facts)
                && $latest->created_at->gt(now()->subDays(config('vehicle_advice.refresh_after')))) {
                return null;
            }

            return $this->generateFrom($vehicle, $locale, $facts);
        });
    }

    /**
     * Generates with the vehicle locked: two runs at the same time (such as a
     * scheduler that runs twice) would otherwise both ask the model, and the
     * tips of the first would never be seen. The second gets null; one that
     * comes after finds the advice up to date.
     *
     * @param  callable(): ?VehicleAdvice  $generate
     *
     * @throws Throwable When the AI model gives no answer.
     */
    private function locked(Vehicle $vehicle, string $locale, callable $generate): ?VehicleAdvice
    {
        $lock = Cache::lock("vehicle-advice:{$vehicle->id}:{$locale}", config('vehicle_advice.lock_for'));

        if (! $lock->get()) {
            return null;
        }

        try {
            return $generate();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     *
     * @throws Throwable When the AI model gives no answer.
     */
    private function generateFrom(Vehicle $vehicle, string $locale, array $facts): VehicleAdvice
    {
        $response = $this->ask($facts, self::LANGUAGES[$locale] ?? 'English');

        return $vehicle->vehicleAdvices()->create([
            'locale' => $locale,
            'suggestions' => $this->suggestions($vehicle, (array) ($response['suggestions'] ?? []), $facts),
            'facts' => $facts,
            'facts_hash' => $this->facts->fingerprint($facts),
            'model' => $response->meta->model ?? config('vehicle_advice.model'),
        ]);
    }

    /**
     * Asks with every API key, in random order, until one gives an answer;
     * when none does, the same with the fallback model.
     *
     * @param  array<string, mixed>  $facts
     *
     * @throws Throwable The last failure, when no key gave an answer.
     */
    private function ask(array $facts, string $language): StructuredAgentResponse
    {
        $failure = null;
        $models = array_values(array_unique(array_filter([config('vehicle_advice.model'), config('vehicle_advice.fallback_model')])));

        foreach ($models as $model) {
            foreach (ApiKeys::shuffled(config('vehicle_advice.provider')) as $number => $key) {
                $advisor = new VehicleAdvisor($language, config('vehicle_advice.suggestions'), $key, $model, config('vehicle_news.tips'));

                try {
                    $response = $advisor->prompt($advisor->question($facts));

                    if (! $response instanceof StructuredAgentResponse) {
                        throw new RuntimeException('The vehicle advisor gave no structured answer.');
                    }

                    return $response;
                } catch (Throwable $exception) {
                    $failure = $exception;

                    Log::warning('Vehicle advice failed, trying the next key or model', [
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
     * What the model made of the suggestions, as far as it makes sense: known
     * categories and actions, no date in the past, nothing the owner handled
     * recently unless it comes back for a change since, every subject once,
     * no tip that only plans a visit to the garage, a finding that is on the
     * garage list a tip only once, and only so many tips about the news.
     *
     * @param  array<int, mixed>  $suggestions
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private function suggestions(Vehicle $vehicle, array $suggestions, array $facts): array
    {
        $handled = collect($facts['handled_suggestions'] ?? []);
        $news = collect($facts['news'] ?? [])->keyBy('id');
        $newsTips = 0;
        // On the garage list, and a tip given already: left out of the facts, but the model may still name it.
        $listedWithTip = Finding::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('for_garage', true)
            ->whereIn('id', VehicleTip::query()->where('vehicle_id', $vehicle->id)->whereNotNull('finding_id')->select('finding_id'))
            ->pluck('id')
            ->all();
        $findings = [...array_column($facts['open_findings'] ?? [], 'id'), ...$listedWithTip];

        return collect($suggestions)
            ->filter(fn ($suggestion) => is_array($suggestion))
            ->map(function (array $suggestion) use ($findings, $news) {
                $key = Str::limit(Str::snake(Str::ascii(trim((string) ($suggestion['key'] ?? '')))), 60, '');
                // Only an article of the facts: the model may make one up.
                $article = $news->get(trim((string) ($suggestion['news_id'] ?? '')));
                $category = in_array($suggestion['category'] ?? null, VehicleAdvice::CATEGORIES, true) ? $suggestion['category'] : null;
                $subject = in_array($suggestion['subject'] ?? null, VehicleAdvice::SUBJECTS, true)
                    ? $suggestion['subject']
                    : VehicleAdvice::inferSubject($key, $category);
                // Only an open finding of the facts: the model may make one up.
                $finding = in_array($suggestion['finding_id'] ?? null, $findings, true) ? (string) $suggestion['finding_id'] : null;

                return [
                    'key' => $key,
                    'subject' => $subject,
                    'finding_id' => $finding,
                    'category' => $category,
                    'icon' => array_key_exists((string) ($suggestion['icon'] ?? ''), VehicleAdvice::ICONS) ? $suggestion['icon'] : null,
                    'priority' => in_array($suggestion['priority'] ?? null, VehicleAdvice::PRIORITIES, true) ? $suggestion['priority'] : 'low',
                    'basis' => in_array($suggestion['basis'] ?? null, VehicleAdvice::BASES, true) ? $suggestion['basis'] : 'general_knowledge',
                    'action' => in_array($suggestion['action'] ?? null, VehicleAdvice::ACTIONS, true) ? $suggestion['action'] : 'none',
                    'due_date' => $this->date((string) ($suggestion['due_date'] ?? '')),
                    'title' => Str::limit(trim((string) ($suggestion['title'] ?? '')), 90),
                    'body' => trim((string) ($suggestion['body'] ?? '')),
                    'returns_because' => trim((string) ($suggestion['returns_because'] ?? '')) ?: null,
                    // Where it comes from, copied: the article is pruned in time.
                    'source' => $article === null ? null : [
                        'news_id' => $article['id'],
                        'url' => VehicleNews::query()->whereKey((int) Str::after($article['id'], 'news:'))->value('url'),
                        'name' => $article['source'],
                        'date' => $article['date'],
                    ],
                ];
            })
            ->filter(fn (array $suggestion) => $suggestion['key'] !== '' && $suggestion['category'] !== null && $suggestion['title'] !== '')
            ->reject(fn (array $suggestion) => in_array($suggestion['subject'], VehicleAdvice::DROPPED_SUBJECTS, true))
            // With a trip planned, a vignette or sticker is the trip planner's: it knows by its own rules.
            ->reject(fn (array $suggestion) => $suggestion['subject'] === 'vignette' && ! empty($facts['planned_trips']))
            ->map(fn (array $suggestion) => $this->returning($suggestion, $handled))
            ->filter()
            // On the garage list already: one tip to prepare the visit was enough.
            ->reject(fn (array $suggestion) => in_array($suggestion['finding_id'], $listedWithTip, true))
            // An article that is gone has no address to show.
            ->map(fn (array $suggestion) => [...$suggestion, 'source' => ($suggestion['source']['url'] ?? null) !== null ? $suggestion['source'] : null])
            ->unique(fn (array $suggestion) => match (true) {
                $suggestion['finding_id'] !== null => 'finding:'.$suggestion['finding_id'],
                in_array($suggestion['subject'], ['other', 'open_finding'], true) => 'key:'.$suggestion['key'],
                default => 'subject:'.$suggestion['subject'],
            })
            // The news should not crowd out the maintenance.
            ->reject(function (array $suggestion) use (&$newsTips) {
                return $suggestion['source'] !== null && ++$newsTips > config('vehicle_news.tips');
            })
            ->take(config('vehicle_advice.suggestions'))
            ->values()
            ->all();
    }

    /**
     * A suggestion about what the owner handled, by its key or its subject
     * (a finding or "other" says too little about what a tip is about: those
     * go by key), only when it comes back for a change since the owner
     * handled it that is listed with every handled tip it is about. Null when
     * it does not; without returns_because when it is about nothing handled.
     *
     * @param  array<string, mixed>  $suggestion
     * @param  Collection<int, array<string, mixed>>  $handled
     * @return array<string, mixed>|null
     */
    private function returning(array $suggestion, Collection $handled): ?array
    {
        $about = $handled->filter(fn (array $tip) => $tip['key'] === $suggestion['key']
            || (! in_array($tip['subject'] ?? null, ['other', 'open_finding'], true) && ($tip['subject'] ?? null) === $suggestion['subject']));

        if ($about->isEmpty()) {
            return [...$suggestion, 'returns_because' => null];
        }

        $because = $suggestion['returns_because'];

        return $because !== null && $about->every(fn (array $tip) => in_array($because, (array) ($tip['changes_since'] ?? []), true))
            ? $suggestion
            : null;
    }

    /**
     * The due day, never in the past. Null when the model named none.
     */
    private function date(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return rescue(fn () => Carbon::createFromFormat('Y-m-d', $value)->startOfDay()->max(today())->toDateString(), null, false);
    }

    public function latest(Vehicle $vehicle, string $locale): ?VehicleAdvice
    {
        return $vehicle->vehicleAdvices()->where('locale', $locale)->latest()->first();
    }

    /**
     * Whether the advice can be generated by hand: not again within minutes.
     */
    public function canGenerate(Vehicle $vehicle, string $locale): bool
    {
        return ! $vehicle->vehicleAdvices()
            ->where('locale', $locale)
            ->where('created_at', '>', now()->subMinutes(config('vehicle_advice.regenerate_after')))
            ->exists();
    }

    /**
     * Marks a tip as done, kept or not relevant: it is no longer shown on the
     * dashboard, and the model is told not to make it again. A tip of the
     * latest advice is copied, so that the overview keeps it; a tip that was
     * handled before only changes its status. False for an unknown tip.
     */
    /**
     * Marks a tip as done, kept or not relevant (see setStatus()). Done, with
     * what was done: recorded with the components of the plan the tip is
     * about, on the components themselves.
     *
     * @param  array{kind: 'checked'|'replaced', components: list<string>, date: string, km: int|null}|null  $record
     */
    public function handle(Vehicle $vehicle, string $key, string $status, ?array $record = null): bool
    {
        if (! $this->setStatus($vehicle, $key, $status)) {
            return false;
        }

        if ($status === VehicleTip::DONE && $record !== null) {
            $tip = VehicleTip::query()->where('vehicle_id', $vehicle->id)->where('key', $key)->sole();
            $allowed = array_column($this->componentsFor($vehicle, $tip->subject), 'key');

            foreach (array_intersect($record['components'], $allowed) as $component) {
                app(MaintenancePlanService::class)->record($vehicle, $component, $record['kind'], CarbonImmutable::parse($record['date']), $record['km']);
            }

            // Handled after what it recorded: that is no change to come back for.
            $tip->touch();
        }

        return true;
    }

    /**
     * The components of the plan of the vehicle a tip of a subject is about,
     * for the owner to say what was done when it is done.
     *
     * @return list<array{key: string, label: string, icon: string}>
     */
    public function componentsFor(Vehicle $vehicle, ?string $subject): array
    {
        $keys = VehicleAdvice::SUBJECT_COMPONENTS[$subject] ?? [];

        if ($keys === []) {
            return [];
        }

        $this->activeComponents[$vehicle->id] ??= app(MaintenancePlanService::class)->components($vehicle)
            ->filter(fn ($component) => $component->active)
            ->keys()
            ->all();

        return collect($keys)
            ->filter(fn (string $key) => in_array($key, $this->activeComponents[$vehicle->id], true))
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => __(config("maintenance_plan.components.{$key}.label")),
                'icon' => config("maintenance_plan.components.{$key}.icon"),
            ])
            ->values()
            ->all();
    }

    private function setStatus(Vehicle $vehicle, string $key, string $status): bool
    {
        $tip = VehicleTip::query()->where('vehicle_id', $vehicle->id)->where('key', $key)->first();
        $locale = app()->getLocale();
        // Shown again, as a tip handled long ago can be: handled as it is now.
        $suggestion = collect($this->latest($vehicle, $locale)->suggestions ?? [])->firstWhere('key', $key);

        if ($tip === null && $suggestion === null) {
            return false;
        }

        $tip ??= new VehicleTip(['vehicle_id' => $vehicle->id]);

        if ($suggestion !== null) {
            $tip->fill([
                'locale' => $locale,
                'subject' => $suggestion['subject'] ?? VehicleAdvice::inferSubject((string) ($suggestion['key'] ?? ''), $suggestion['category'] ?? null),
                'finding_id' => $suggestion['finding_id'] ?? null,
                ...Arr::only($suggestion, ['key', 'category', 'icon', 'priority', 'basis', 'action', 'due_date', 'title', 'body']),
                'source' => $suggestion['source'] ?? null,
            ]);
        }

        // Handled now, also when the status stays the same: how long it counts goes from here.
        $tip->fill(['status' => $status])->forceFill(['updated_at' => now()])->save();

        return true;
    }

    /**
     * The tips of the latest advice in the current locale that were not
     * handled since, or came back for a change since they were.
     *
     * @return list<array<string, mixed>>
     */
    private function current(Vehicle $vehicle, ?VehicleAdvice $advice): array
    {
        if ($advice === null) {
            return [];
        }

        $inForce = VehicleTip::inForce($vehicle);
        $priorities = array_flip(VehicleAdvice::PRIORITIES);

        return collect($advice->suggestions)
            ->reject(fn (array $suggestion) => VehicleTip::hides($suggestion, $advice->created_at, $inForce))
            ->sortBy(fn (array $suggestion) => $priorities[$suggestion['priority']] ?? count($priorities))
            ->values()
            ->all();
    }

    /**
     * The advice in the current locale, for the dashboard: the tips that were
     * not handled yet, each new or updated since the advice before, and with
     * when it was done or kept before when it came back.
     *
     * @return array<string, mixed>
     */
    public function forDashboard(Vehicle $vehicle): array
    {
        $locale = app()->getLocale();
        $advice = $this->latest($vehicle, $locale);
        $before = $advice === null ? collect() : collect($vehicle->vehicleAdvices()
            ->where('locale', $locale)
            ->where('created_at', '<', $advice->created_at)
            ->latest()
            ->first()
            ->suggestions ?? [])->keyBy('key');
        $handled = $advice === null ? collect() : VehicleTip::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [VehicleTip::DONE, VehicleTip::KEPT])
            ->whereIn('key', array_column($advice->suggestions ?? [], 'key'))
            ->get()
            ->keyBy('key');

        return [
            'advice' => $advice ? [
                'id' => $advice->id,
                'suggestions' => array_map(fn (array $suggestion) => [
                    ...$this->present($vehicle, $suggestion),
                    'badge' => match (true) {
                        ! empty($suggestion['returns_because']) => 'updated',
                        ! $before->has($suggestion['key']) => 'new',
                        $before[$suggestion['key']]['title'] !== $suggestion['title'] || $before[$suggestion['key']]['body'] !== $suggestion['body'] => 'updated',
                        default => null,
                    },
                    'lastHandled' => ($tip = $handled->get($suggestion['key'])) === null ? null : [
                        'status' => $tip->status,
                        'at' => $tip->updated_at->isoFormat('D MMMM YYYY'),
                    ],
                ], $this->current($vehicle, $advice)),
                'model' => $advice->model,
                'generatedAt' => $advice->created_at->isoFormat('D MMMM HH:mm'),
            ] : null,
            'canGenerate' => $this->canGenerate($vehicle, $locale),
            // The defaults of what was done when a tip is done.
            'today' => today()->toDateString(),
            'mileage' => $vehicle->mileage_latest ?: null,
            'generateUrl' => route('vehicle-advice.store'),
            'statusUrl' => route('vehicle-advice.status'),
            'overviewUrl' => route('tips'),
        ];
    }

    /**
     * All tips, for the overview: the current ones as on the dashboard, and
     * the ones kept or done, the most recently handled first. One that came
     * back is only among the current ones. The ones that are not relevant
     * are left out altogether.
     *
     * @return array<string, mixed>
     */
    public function overview(Vehicle $vehicle): array
    {
        $dashboard = $this->forDashboard($vehicle);
        $current = array_column($dashboard['advice']['suggestions'] ?? [], 'key');

        $handled = VehicleTip::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [VehicleTip::KEPT, VehicleTip::DONE])
            ->whereNotIn('key', $current)
            ->latest('updated_at')
            ->get()
            ->groupBy('status');

        $present = fn (VehicleTip $tip) => [
            ...$this->present($vehicle, [...$tip->only(['key', 'subject', 'category', 'icon', 'priority', 'basis', 'action', 'title', 'body', 'source']), 'due_date' => $tip->due_date?->toDateString()]),
            'handledAt' => $tip->updated_at->isoFormat('D MMMM YYYY'),
        ];

        return [
            ...$dashboard,
            'kept' => $handled->get(VehicleTip::KEPT, collect())->map($present)->values()->all(),
            'done' => $handled->get(VehicleTip::DONE, collect())->map($present)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function present(Vehicle $vehicle, array $suggestion): array
    {
        [$url, $label] = match ($suggestion['action']) {
            'add_maintenance' => [route('maintenances.create'), __('Add maintenance')],
            'add_reconditioning' => [route('reconditionings.create'), __('Reconditioning & washing')],
            'view_insurances' => [route('insurances.index'), __('Insurances')],
            'view_taxes' => [route('taxes.index'), __('Road taxes')],
            'view_vehicle' => [route('vehicles.show', $vehicle), __('Vehicle')],
            default => [null, null],
        };

        $due = $suggestion['due_date'] ? Carbon::parse($suggestion['due_date']) : null;
        $subject = $suggestion['subject'] ?? VehicleAdvice::inferSubject((string) $suggestion['key'], $suggestion['category'] ?? null);

        return [
            'key' => $suggestion['key'],
            // What can be recorded when it is done: checked or replaced.
            'components' => $this->componentsFor($vehicle, $subject),
            'category' => $suggestion['category'],
            'icon' => $this->icon($suggestion),
            'priority' => $suggestion['priority'],
            'basis' => $suggestion['basis'],
            'title' => $suggestion['title'],
            'body' => $suggestion['body'],
            'due' => $due?->isoFormat('D MMMM YYYY'),
            'url' => $url,
            'urlLabel' => $label,
            // The article it is based on.
            'source' => empty($suggestion['source']['url']) ? null : [
                'url' => $suggestion['source']['url'],
                'name' => $suggestion['source']['name'] ?? Str::after((string) parse_url($suggestion['source']['url'], PHP_URL_HOST), 'www.'),
                'date' => empty($suggestion['source']['date']) ? null : Carbon::parse($suggestion['source']['date'])->isoFormat('D MMMM YYYY'),
            ],
        ];
    }

    /**
     * The icon the model chose, or else the one of the category.
     *
     * @param  array<string, mixed>  $suggestion
     */
    private function icon(array $suggestion): string
    {
        return array_key_exists((string) ($suggestion['icon'] ?? ''), VehicleAdvice::ICONS)
            ? $suggestion['icon']
            : self::ICONS[$suggestion['category']] ?? 'mdi:lightbulb-on-outline';
    }

    /**
     * The suggestions of high priority that are due soon (or have no date),
     * as notifications under "Needs attention".
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        $types = StatusNotification::types();
        $within = today()->addDays(config('vehicle_advice.attention_within'));

        return collect($this->current($vehicle, $this->latest($vehicle, app()->getLocale())))
            ->filter(fn (array $suggestion) => $suggestion['priority'] === 'high'
                && ($suggestion['due_date'] === null || Carbon::parse($suggestion['due_date'])->lte($within)))
            ->map(fn (array $suggestion) => [
                ...$types['warning'],
                'key' => 'vehicle_advice:'.$suggestion['key'],
                'type' => 'warning',
                'text' => $suggestion['title'],
                'icon' => $this->icon($suggestion),
                'typeIcon' => $types['warning']['icon'],
                'priority' => $types['warning']['priority'],
                'hasModal' => false,
                'data' => [],
                'opensVehicleAdvice' => true,
            ])
            ->values()
            ->all();
    }
}
