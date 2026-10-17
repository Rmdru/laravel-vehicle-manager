<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\VehicleAdvisor;
use App\Ai\ApiKeys;
use App\Models\Vehicle;
use App\Models\VehicleAdvice;
use App\Models\VehicleAdviceDismissal;
use App\Support\StatusNotification;
use Carbon\Carbon;
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

    /** The icon of each category, as on the dashboard. */
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
        $dismissed = array_column($facts['dismissed_suggestions'], 'key');

        return $vehicle->vehicleAdvices()->create([
            'locale' => $locale,
            'suggestions' => $this->suggestions((array) ($response['suggestions'] ?? []), $dismissed),
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
     * categories and actions, no date in the past, nothing the owner dismissed
     * and every subject once.
     *
     * @param  array<int, mixed>  $suggestions
     * @param  list<string>  $dismissed
     * @return list<array<string, mixed>>
     */
    private function suggestions(array $suggestions, array $dismissed): array
    {
        return collect($suggestions)
            ->filter(fn ($suggestion) => is_array($suggestion))
            ->map(fn (array $suggestion) => [
                'key' => Str::limit(Str::snake(Str::ascii(trim((string) ($suggestion['key'] ?? '')))), 60, ''),
                'category' => in_array($suggestion['category'] ?? null, VehicleAdvice::CATEGORIES, true) ? $suggestion['category'] : null,
                'priority' => in_array($suggestion['priority'] ?? null, VehicleAdvice::PRIORITIES, true) ? $suggestion['priority'] : 'low',
                'basis' => in_array($suggestion['basis'] ?? null, VehicleAdvice::BASES, true) ? $suggestion['basis'] : 'general_knowledge',
                'action' => in_array($suggestion['action'] ?? null, VehicleAdvice::ACTIONS, true) ? $suggestion['action'] : 'none',
                'due_date' => $this->date((string) ($suggestion['due_date'] ?? '')),
                'title' => Str::limit(trim((string) ($suggestion['title'] ?? '')), 90),
                'body' => trim((string) ($suggestion['body'] ?? '')),
            ])
            ->filter(fn (array $suggestion) => $suggestion['key'] !== '' && $suggestion['category'] !== null && $suggestion['title'] !== '')
            ->reject(fn (array $suggestion) => in_array($suggestion['key'], $dismissed, true))
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
     * Marks a suggestion of the latest advice as done or as not relevant: it
     * is no longer shown, and the model is told not to make it again.
     */
    public function dismiss(Vehicle $vehicle, string $key, string $reason): void
    {
        $suggestion = collect($this->latest($vehicle, app()->getLocale())?->suggestions ?? [])->firstWhere('key', $key);

        VehicleAdviceDismissal::query()->updateOrCreate(
            ['vehicle_id' => $vehicle->id, 'key' => $key],
            ['reason' => $reason, 'title' => (string) ($suggestion['title'] ?? $key)],
        );
    }

    /**
     * The suggestions in the current locale that were not dismissed since.
     *
     * @return list<array<string, mixed>>
     */
    private function current(Vehicle $vehicle, ?VehicleAdvice $advice): array
    {
        if ($advice === null) {
            return [];
        }

        $dismissed = VehicleAdviceDismissal::query()->where('vehicle_id', $vehicle->id)->pluck('key')->all();

        return collect($advice->suggestions)
            ->reject(fn (array $suggestion) => in_array($suggestion['key'], $dismissed, true))
            ->values()
            ->all();
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
        $priorities = array_flip(VehicleAdvice::PRIORITIES);

        return [
            'advice' => $advice ? [
                'id' => $advice->id,
                'suggestions' => collect($this->current($vehicle, $advice))
                    ->sortBy(fn (array $suggestion) => $priorities[$suggestion['priority']] ?? 9)
                    ->map(fn (array $suggestion) => $this->present($vehicle, $suggestion))
                    ->values()
                    ->all(),
                'model' => $advice->model,
                'generatedAt' => $advice->created_at->isoFormat('D MMMM HH:mm'),
            ] : null,
            'canGenerate' => $this->canGenerate($vehicle, $locale),
            'generateUrl' => route('vehicle-advice.store'),
            'dismissUrl' => route('vehicle-advice.dismiss'),
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
            'icon' => self::ICONS[$suggestion['category']] ?? 'mdi:lightbulb-on-outline',
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
                'icon' => self::ICONS[$suggestion['category']] ?? 'mdi:lightbulb-on-outline',
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
