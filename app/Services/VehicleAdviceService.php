<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\VehicleAdvisor;
use App\Ai\ApiKeys;
use App\Models\Vehicle;
use App\Models\VehicleAdvice;
use App\Models\VehicleTip;
use App\Support\StatusNotification;
use Carbon\Carbon;
use Illuminate\Support\Arr;
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
    private const LANGUAGES = [
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

    public function __construct(private readonly VehicleAdviceFacts $facts)
    {
        //
    }

    /**
     * @throws Throwable When the AI model gives no answer.
     */
    public function generate(Vehicle $vehicle, string $locale): VehicleAdvice
    {
        return $this->generateFrom($vehicle, $locale, $this->facts->for($vehicle));
    }

    /**
     * The advice is generated again by the daily run when the facts changed,
     * or when it is a week old: the season, and what it calls for, change too.
     * Null when it is still up to date.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function refresh(Vehicle $vehicle, string $locale): ?VehicleAdvice
    {
        $facts = $this->facts->for($vehicle);
        $latest = $this->latest($vehicle, $locale);

        if ($latest !== null
            && $latest->facts_hash === $this->facts->fingerprint($facts)
            && $latest->created_at->gt(now()->subDays(config('vehicle_advice.refresh_after')))) {
            return null;
        }

        return $this->generateFrom($vehicle, $locale, $facts);
    }

    /**
     * @param  array<string, mixed>  $facts
     *
     * @throws Throwable When the AI model gives no answer.
     */
    private function generateFrom(Vehicle $vehicle, string $locale, array $facts): VehicleAdvice
    {
        $response = $this->ask($facts, self::LANGUAGES[$locale] ?? 'English');
        $handled = array_column($facts['handled_suggestions'], 'key');

        return $vehicle->vehicleAdvices()->create([
            'locale' => $locale,
            'suggestions' => $this->suggestions((array) ($response['suggestions'] ?? []), $handled),
            'facts' => $facts,
            'facts_hash' => $this->facts->fingerprint($facts),
            'model' => $response->meta->model ?? config('vehicle_advice.model'),
        ]);
    }

    /**
     * Asks with every API key, in random order, until one gives an answer.
     *
     * @param  array<string, mixed>  $facts
     *
     * @throws Throwable The last failure, when no key gave an answer.
     */
    private function ask(array $facts, string $language): StructuredAgentResponse
    {
        $failure = null;

        foreach (ApiKeys::shuffled(config('vehicle_advice.provider')) as $number => $key) {
            $advisor = new VehicleAdvisor($language, config('vehicle_advice.suggestions'), $key);

            try {
                $response = $advisor->prompt($advisor->question($facts));

                if (! $response instanceof StructuredAgentResponse) {
                    throw new RuntimeException('The vehicle advisor gave no structured answer.');
                }

                return $response;
            } catch (Throwable $exception) {
                $failure = $exception;

                Log::warning('Vehicle advice failed, trying the next key', [
                    'key' => $key === null ? null : $number + 1,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        throw $failure;
    }

    /**
     * What the model made of the suggestions, as far as it makes sense: known
     * categories and actions, no date in the past, nothing the owner handled
     * and every subject once.
     *
     * @param  array<int, mixed>  $suggestions
     * @param  list<string>  $handled
     * @return list<array<string, mixed>>
     */
    private function suggestions(array $suggestions, array $handled): array
    {
        return collect($suggestions)
            ->filter(fn ($suggestion) => is_array($suggestion))
            ->map(fn (array $suggestion) => [
                'key' => Str::limit(Str::snake(Str::ascii(trim((string) ($suggestion['key'] ?? '')))), 60, ''),
                'category' => in_array($suggestion['category'] ?? null, VehicleAdvice::CATEGORIES, true) ? $suggestion['category'] : null,
                'icon' => array_key_exists((string) ($suggestion['icon'] ?? ''), VehicleAdvice::ICONS) ? $suggestion['icon'] : null,
                'priority' => in_array($suggestion['priority'] ?? null, VehicleAdvice::PRIORITIES, true) ? $suggestion['priority'] : 'low',
                'basis' => in_array($suggestion['basis'] ?? null, VehicleAdvice::BASES, true) ? $suggestion['basis'] : 'general_knowledge',
                'action' => in_array($suggestion['action'] ?? null, VehicleAdvice::ACTIONS, true) ? $suggestion['action'] : 'none',
                'due_date' => $this->date((string) ($suggestion['due_date'] ?? '')),
                'title' => Str::limit(trim((string) ($suggestion['title'] ?? '')), 90),
                'body' => trim((string) ($suggestion['body'] ?? '')),
            ])
            ->filter(fn (array $suggestion) => $suggestion['key'] !== '' && $suggestion['category'] !== null && $suggestion['title'] !== '')
            ->reject(fn (array $suggestion) => in_array($suggestion['key'], $handled, true))
            ->unique('key')
            ->take(config('vehicle_advice.suggestions'))
            ->values()
            ->all();
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
    public function handle(Vehicle $vehicle, string $key, string $status): bool
    {
        $tip = VehicleTip::query()->where('vehicle_id', $vehicle->id)->where('key', $key)->first();

        if ($tip !== null) {
            $tip->update(['status' => $status]);

            return true;
        }

        $locale = app()->getLocale();
        $suggestion = collect($this->latest($vehicle, $locale)->suggestions ?? [])->firstWhere('key', $key);

        if ($suggestion === null) {
            return false;
        }

        VehicleTip::query()->create([
            'vehicle_id' => $vehicle->id,
            'status' => $status,
            'locale' => $locale,
            ...Arr::only($suggestion, ['key', 'category', 'icon', 'priority', 'basis', 'action', 'due_date', 'title', 'body']),
        ]);

        return true;
    }

    /**
     * The tips of the latest advice in the current locale that were not
     * handled since.
     *
     * @return list<array<string, mixed>>
     */
    private function current(Vehicle $vehicle, ?VehicleAdvice $advice): array
    {
        if ($advice === null) {
            return [];
        }

        $handled = VehicleTip::query()->where('vehicle_id', $vehicle->id)->pluck('key')->all();
        $priorities = array_flip(VehicleAdvice::PRIORITIES);

        return collect($advice->suggestions)
            ->reject(fn (array $suggestion) => in_array($suggestion['key'], $handled, true))
            ->sortBy(fn (array $suggestion) => $priorities[$suggestion['priority']] ?? count($priorities))
            ->values()
            ->all();
    }

    /**
     * The advice in the current locale, for the dashboard: the tips that were
     * not handled yet.
     *
     * @return array<string, mixed>
     */
    public function forDashboard(Vehicle $vehicle): array
    {
        $locale = app()->getLocale();
        $advice = $this->latest($vehicle, $locale);

        return [
            'advice' => $advice ? [
                'id' => $advice->id,
                'suggestions' => array_map(fn (array $suggestion) => $this->present($vehicle, $suggestion), $this->current($vehicle, $advice)),
                'model' => $advice->model,
                'generatedAt' => $advice->created_at->isoFormat('D MMMM HH:mm'),
            ] : null,
            'canGenerate' => $this->canGenerate($vehicle, $locale),
            'generateUrl' => route('vehicle-advice.store'),
            'statusUrl' => route('vehicle-advice.status'),
            'overviewUrl' => route('tips'),
        ];
    }

    /**
     * All tips, for the overview: the current ones as on the dashboard, and
     * the ones kept or done, the most recently handled first. The ones that
     * are not relevant are left out altogether.
     *
     * @return array<string, mixed>
     */
    public function overview(Vehicle $vehicle): array
    {
        $handled = VehicleTip::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [VehicleTip::KEPT, VehicleTip::DONE])
            ->latest('updated_at')
            ->get()
            ->groupBy('status');

        $present = fn (VehicleTip $tip) => [
            ...$this->present($vehicle, [...$tip->only(['key', 'category', 'icon', 'priority', 'basis', 'action', 'title', 'body']), 'due_date' => $tip->due_date?->toDateString()]),
            'handledAt' => $tip->updated_at->isoFormat('D MMMM YYYY'),
        ];

        return [
            ...$this->forDashboard($vehicle),
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

        return [
            'key' => $suggestion['key'],
            'category' => $suggestion['category'],
            'icon' => $this->icon($suggestion),
            'priority' => $suggestion['priority'],
            'basis' => $suggestion['basis'],
            'title' => $suggestion['title'],
            'body' => $suggestion['body'],
            'due' => $due?->isoFormat('D MMMM YYYY'),
            'url' => $url,
            'urlLabel' => $label,
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
