<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\ComplaintDiagnoser;
use App\Ai\ApiKeys;
use App\Enums\FindingSeverity;
use App\Enums\FindingSource;
use App\Enums\FindingStatus;
use App\Models\Finding;
use App\Models\Maintenance;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * The findings of a vehicle: what needs attention until it is resolved, as
 * notifications, on the list for the garage, and resolved with a maintenance.
 */
class FindingService
{
    /** Characters of a description the model gets to see, at most. */
    private const DESCRIPTION = 300;

    /**
     * The open findings of a vehicle, the most urgent first, then the oldest.
     *
     * @return Collection<int, Finding>
     */
    public function open(Vehicle $vehicle): Collection
    {
        $severities = array_flip(array_map(fn (FindingSeverity $severity) => $severity->value, FindingSeverity::cases()));

        return $vehicle->findings()->open()->orderBy('date')->get()
            ->sortBy(fn (Finding $finding) => $severities[$finding->severity->value])
            ->values();
    }

    /**
     * The defects the latest MOT found, as findings for information: the RDW
     * does not tell whether they were repaired at once. Each one only once,
     * also when the owner deleted it; none of an MOT long ago.
     *
     * @return int The number of findings added.
     */
    public function importMot(Vehicle $vehicle): int
    {
        $inspection = $vehicle->latestInspection(today()->subMonths(config('findings.import_mot_within_months')));

        if ($inspection === null || empty($inspection['defects'])) {
            return 0;
        }

        $date = Carbon::parse($inspection['date'])->toDateString();
        $added = 0;

        foreach ($inspection['defects'] as $defect) {
            $exists = $vehicle->findings()->withTrashed()
                ->where('source', FindingSource::MotDefect->value)
                ->where('rdw_code', (string) $defect['code'])
                ->whereDate('date', $date)
                ->exists();

            if ($exists) {
                continue;
            }

            $vehicle->findings()->create([
                'source' => FindingSource::MotDefect,
                'severity' => FindingSeverity::Info,
                'date' => $date,
                'title' => Str::limit(Str::ucfirst((string) $defect['description']), 250),
                'description' => collect([
                    $inspection['type'],
                    filled($defect['article'] ?? null) ? __('Article :article', ['article' => $defect['article']]) : null,
                    ($defect['count'] ?? 1) > 1 ? __(':count times', ['count' => $defect['count']]) : null,
                ])->filter()->implode(' · ') ?: null,
                'rdw_code' => (string) $defect['code'],
            ]);

            $added++;
        }

        return $added;
    }

    /**
     * The open findings a maintenance can resolve, for its form: by id.
     *
     * @return array<string, string>
     */
    public function options(Vehicle $vehicle, ?Maintenance $maintenance = null): array
    {
        return $vehicle->findings()
            ->where(fn ($query) => $query->open()->when($maintenance, fn ($query) => $query->orWhere('maintenance_id', $maintenance->id)))
            ->orderByDesc('for_garage')
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn (Finding $finding) => [$finding->id => $finding->title])
            ->all();
    }

    /**
     * Resolves the findings that were ticked with a maintenance; those that
     * were ticked before and no longer are, are open again.
     *
     * @param  list<string>  $ids
     */
    public function resolveWith(Maintenance $maintenance, array $ids): void
    {
        $findings = fn () => Finding::query()->where('vehicle_id', $maintenance->vehicle_id);

        $findings()->where('maintenance_id', $maintenance->id)->whereNotIn('id', $ids)->get()
            ->each(fn (Finding $finding) => $finding->update(['status' => FindingStatus::Open, 'resolved_date' => null, 'maintenance_id' => null]));

        $findings()->whereIn('id', $ids)->get()
            ->reject(fn (Finding $finding) => $finding->maintenance_id === $maintenance->id)
            ->each(fn (Finding $finding) => $finding->resolve($maintenance));
    }

    /**
     * Puts a component of the maintenance plan on the list for the garage, as
     * a finding of its own; the open one when there is one already.
     */
    public function addFromPlan(Vehicle $vehicle, string $key): Finding
    {
        $existing = $vehicle->findings()->open()->whereJsonContains('components', $key)->first();

        if ($existing !== null) {
            $existing->update(['for_garage' => true]);

            return $existing;
        }

        return $vehicle->findings()->create([
            'source' => FindingSource::MaintenancePlan,
            'severity' => FindingSeverity::Soon,
            'date' => today()->toDateString(),
            'mileage' => $vehicle->mileage_latest ?: null,
            'title' => __(config("maintenance_plan.components.{$key}.label")),
            'components' => [$key],
            'for_garage' => true,
        ]);
    }

    /**
     * The list for the garage: the findings on it, and the components of the
     * maintenance plan that are due and not on it yet, to add with a click.
     *
     * @return array<string, mixed>
     */
    public function forGarage(Vehicle $vehicle, MaintenancePlanService $plan): array
    {
        $open = $this->open($vehicle);
        $listed = $open->where('for_garage', true)->values();
        $covered = $listed->flatMap(fn (Finding $finding) => (array) $finding->components)->unique();

        return [
            'items' => $listed->map(fn (Finding $finding) => $this->present($finding))->all(),
            // Open findings not on the list: to add with a click.
            'others' => $open->where('for_garage', false)->map(fn (Finding $finding) => $this->present($finding))->values()->all(),
            'plan' => collect($plan->plan($vehicle))
                ->filter(fn (array $item) => in_array($item['state'], [MaintenancePlanService::OVERDUE, MaintenancePlanService::DUE, MaintenancePlanService::SOON, MaintenancePlanService::CHECK], true))
                ->reject(fn (array $item) => $covered->contains($item['key']))
                ->map(fn (array $item) => [
                    'key' => $item['key'],
                    'label' => __(config("maintenance_plan.components.{$item['key']}.label")),
                    'icon' => config("maintenance_plan.components.{$item['key']}.icon"),
                    'state' => $item['state'],
                    'due' => $item['due_date'] && $item['state'] !== MaintenancePlanService::CHECK ? Carbon::parse($item['due_date'])->isoFormat('D MMMM YYYY') : null,
                    'addUrl' => route('for-the-garage.plan', $item['key']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Finding $finding): array
    {
        return [
            'id' => $finding->id,
            'title' => $finding->title,
            'description' => $finding->description,
            'source' => $finding->source->value,
            'sourceLabel' => $finding->source->getLabel(),
            'sourceIcon' => $finding->source->getIcon(),
            'severity' => $finding->severity->value,
            'severityLabel' => $finding->severity->getLabel(),
            'date' => $finding->date->isoFormat('D MMMM YYYY'),
            'garage' => $finding->garage,
            'estimatedCost' => $finding->estimated_cost ? '€ '.number_format($finding->estimated_cost, 2, ',', '.') : null,
            'components' => collect((array) $finding->components)->map(fn (string $key) => __(config("maintenance_plan.components.{$key}.label", $key)))->values()->all(),
            'forGarage' => $finding->for_garage,
            'url' => route('findings.show', $finding->id),
            'editUrl' => route('findings.edit', $finding->id),
            'garageUrl' => route('findings.garage', $finding->id),
            'resolveUrl' => route('findings.resolve', $finding->id),
        ];
    }

    /**
     * The open findings, for the facts of the tips of the vehicle.
     *
     * @return list<array<string, mixed>>
     */
    public function facts(Vehicle $vehicle): array
    {
        return $this->open($vehicle)
            ->map(fn (Finding $finding) => array_filter([
                'title' => $finding->title,
                'source' => $finding->source->value,
                'severity' => $finding->severity->value,
                'since' => $finding->date->toDateString(),
                'components' => $finding->components ?: null,
                'on_the_list_for_the_garage' => $finding->for_garage ?: null,
                'description' => filled($finding->description) ? Str::limit((string) $finding->description, self::DESCRIPTION) : null,
            ], fn ($value) => $value !== null && $value !== ''))
            ->values()
            ->all();
    }

    /**
     * Whether a complaint can be diagnosed: not again within minutes.
     */
    public function canDiagnose(Finding $finding): bool
    {
        return $finding->source === FindingSource::Complaint
            && ($finding->diagnosed_at === null || $finding->diagnosed_at->lt(now()->subMinutes(config('findings.diagnose_again_after'))));
    }

    /**
     * Has the AI model say what a complaint may come from, and keeps that
     * with the finding.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function diagnose(Finding $finding, string $locale): Finding
    {
        $language = VehicleAdviceService::LANGUAGES[$locale] ?? 'English';
        $response = ApiKeys::ask(
            config('findings.provider'),
            fn (?string $key) => new ComplaintDiagnoser($language, $key),
            (new ComplaintDiagnoser($language))->question($this->diagnosisFacts($finding)),
        );

        $text = fn ($value, int $limit) => Str::limit(trim(strip_tags((string) $value)), $limit);

        $finding->update([
            'diagnosis' => [
                'causes' => collect((array) ($response['causes'] ?? []))
                    ->filter(fn ($cause) => is_array($cause) && filled($cause['cause'] ?? null))
                    ->map(fn (array $cause) => [
                        'cause' => $text($cause['cause'], 120),
                        'likelihood' => in_array($cause['likelihood'] ?? null, ComplaintDiagnoser::LIKELIHOODS, true) ? $cause['likelihood'] : 'medium',
                        'explanation' => $text($cause['explanation'] ?? '', 600),
                        'cost_range' => $text($cause['cost_range'] ?? '', 60),
                    ])
                    ->take(4)
                    ->values()
                    ->all(),
                'driving' => in_array($response['driving'] ?? null, ComplaintDiagnoser::DRIVING, true) ? $response['driving'] : 'careful',
                'driving_explanation' => $text($response['driving_explanation'] ?? '', 600),
                'what_to_tell_the_garage' => $text($response['what_to_tell_the_garage'] ?? '', 800),
                'self_checks' => collect((array) ($response['self_checks'] ?? []))->map(fn ($check) => $text($check, 200))->filter()->take(3)->values()->all(),
                'model' => $response->meta->model ?? config('findings.model'),
                'locale' => $locale,
            ],
            'diagnosed_at' => now(),
        ]);

        return $finding;
    }

    /**
     * What the model gets to know of the complaint and the vehicle, without
     * anything that tells who the owner is.
     *
     * @return array<string, mixed>
     */
    private function diagnosisFacts(Finding $finding): array
    {
        $vehicle = $finding->vehicle;
        $vehicle->loadMissing(['maintenances', 'refuelings']);

        return [
            'complaint' => array_filter([
                'title' => $finding->title,
                'description' => $finding->description,
                'noticed_on' => $finding->date->toDateString(),
                'mileage_km' => $finding->mileage,
                'components' => $finding->components ?: null,
            ]),
            'vehicle' => array_filter([
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'version' => $vehicle->version,
                'engine' => $vehicle->engine,
                'powertrain' => $vehicle->powertrain,
                'construction_date' => $vehicle->construction_date?->toDateString(),
                'mileage_km' => $vehicle->mileage_latest ?: null,
            ]),
            'recent_maintenance' => $vehicle->maintenances->sortByDesc('date')->take(8)
                ->map(fn (Maintenance $maintenance) => array_filter([
                    'date' => $maintenance->date->toDateString(),
                    'mileage_km' => $maintenance->mileage ?: null,
                    'tasks' => collect($maintenance->tasks ?? [])->pluck('task')->filter()->values()->all() ?: null,
                    'description' => filled($maintenance->description) ? Str::limit(strip_tags((string) $maintenance->description), self::DESCRIPTION) : null,
                ]))
                ->values()
                ->all(),
            'maintenance_plan' => collect(app(MaintenancePlanService::class)->plan($vehicle))
                ->map(fn (array $item) => array_filter([
                    'component' => $item['key'],
                    'state' => $item['state'],
                    'last_done' => $item['done']['date'] ?? null,
                ]))
                ->values()
                ->all(),
            'other_open_findings' => collect($this->facts($vehicle))->reject(fn (array $fact) => $fact['title'] === $finding->title)->values()->all(),
        ];
    }
}
