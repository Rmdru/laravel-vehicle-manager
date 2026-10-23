<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\MonthlySummarizer;
use App\Ai\ApiKeys;
use App\Models\Vehicle;
use App\Models\VehicleSummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The summary of the month at the top of the dashboard: the figures as the
 * app calculates them, the deadlines to come, and a few sentences by an AI
 * model on what stood out.
 *
 * The sentences are written when the dashboard is opened and the facts
 * changed, after the response has been sent: the dashboard shows the figures
 * at once, and the sentences as soon as they are there.
 */
class MonthlySummaryService
{
    /** The icon of each kind of deadline, as on the pages of its records. */
    private const ICONS = [
        'mot' => 'ic:baseline-security',
        'maintenance' => 'mdi:car-wrench',
        'insurance' => 'mdi:shield-car',
        'road_tax' => 'mdi:highway',
        'vignette' => 'mdi:sticker-text',
        'environmental_sticker' => 'fa7-solid:leaf',
        'component' => 'mdi:car-wrench',
    ];

    /** The page of each kind of deadline. */
    private const ROUTES = [
        'mot' => 'maintenances.index',
        'maintenance' => 'maintenances.index',
        'insurance' => 'insurances.index',
        'road_tax' => 'taxes.index',
        'vignette' => 'vignettes.index',
        'environmental_sticker' => 'environmental-stickers.index',
        'component' => 'maintenance-plan',
    ];

    public function __construct(private readonly MonthlySummaryFacts $facts)
    {
        //
    }

    /**
     * @param  array<string, mixed>|null  $facts  The facts, when they are calculated already.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function generate(Vehicle $vehicle, string $locale, ?array $facts = null): VehicleSummary
    {
        $facts ??= $this->facts->for($vehicle);
        $language = VehicleAdviceService::LANGUAGES[$locale] ?? 'English';
        $summarizer = fn (?string $key) => new MonthlySummarizer($language, $key);
        $response = ApiKeys::ask(config('monthly_summary.provider'), $summarizer, $summarizer(null)->question($facts));

        if (trim((string) ($response['text'] ?? '')) === '') {
            throw new RuntimeException('The monthly summarizer gave no text.');
        }

        return $vehicle->vehicleSummaries()->create([
            'locale' => $locale,
            'month' => $facts['period']['month'],
            'kind' => $facts['period']['kind'],
            'headline' => Str::limit(trim(strip_tags((string) ($response['headline'] ?? ''))), 80),
            'text' => Str::limit(trim(strip_tags((string) ($response['text'] ?? ''))), 600),
            'facts' => $facts,
            'facts_hash' => $this->facts->fingerprint($facts),
            'model' => $response->meta->model ?? config('monthly_summary.model'),
        ]);
    }

    /**
     * The latest summary of the period in the current locale.
     *
     * @param  array<string, mixed>  $facts
     */
    private function latest(Vehicle $vehicle, string $locale, array $facts): ?VehicleSummary
    {
        return $vehicle->vehicleSummaries()
            ->where('locale', $locale)
            ->where('month', $facts['period']['month'])
            ->where('kind', $facts['period']['kind'])
            ->latest()
            ->first();
    }

    /**
     * Whether anything was recorded in the period to sum up: without it, the
     * model is not asked.
     *
     * @param  array<string, mixed>  $facts
     */
    private function hasRecords(array $facts): bool
    {
        return ($facts['distance']['km'] ?? 0) > 0 || ($facts['costs']['total_eur'] ?? 0) > 0 || $facts['consumption'] !== null;
    }

    /**
     * Has the summary written after the response, unless that was tried for
     * this vehicle only minutes ago. True while it is being written.
     *
     * @param  array<string, mixed>  $facts
     */
    private function writeLater(Vehicle $vehicle, string $locale, array $facts): bool
    {
        $key = "monthly-summary:{$vehicle->id}:{$locale}";

        if (Cache::add($key.':tried', true, now()->addMinutes(config('monthly_summary.regenerate_after')))) {
            Cache::put($key.':writing', true, now()->addSeconds(config('monthly_summary.timeout') * 2));

            defer(function () use ($vehicle, $locale, $facts, $key) {
                try {
                    $this->generate($vehicle, $locale, $facts);
                } catch (Throwable $exception) {
                    report($exception);
                } finally {
                    Cache::forget($key.':writing');
                }
            });
        }

        return Cache::has($key.':writing');
    }

    /**
     * @return array<string, mixed>
     */
    public function forDashboard(Vehicle $vehicle): array
    {
        $locale = app()->getLocale();
        $facts = $this->facts->for($vehicle);
        $summary = $this->latest($vehicle, $locale, $facts);
        $hasRecords = $this->hasRecords($facts);
        $current = $summary !== null && $summary->facts_hash === $this->facts->fingerprint($facts);
        $month = Carbon::parse($facts['period']['start']);

        return [
            'title' => $facts['period']['kind'] === VehicleSummary::PREVIOUS_MONTH ? __('Last month') : __('This month'),
            'period' => (string) str($month->isoFormat('MMMM YYYY'))->ucfirst(),
            'hasRecords' => $hasRecords,
            'figures' => $hasRecords ? $this->figures($facts) : [],
            'upcoming' => $this->upcoming($facts['upcoming']),
            'upcomingWithin' => config('monthly_summary.upcoming_within'),
            'summary' => $summary && $hasRecords ? [
                'headline' => $summary->headline,
                'text' => $summary->text,
                'model' => $summary->model,
                'generatedAt' => $summary->created_at->isoFormat('D MMMM HH:mm'),
            ] : null,
            'pending' => $hasRecords && ! $current && $this->writeLater($vehicle, $locale, $facts),
        ];
    }

    /**
     * The distance, the costs and the consumption, each against the average.
     *
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private function figures(array $facts): array
    {
        $distance = $facts['distance'];
        $costs = $facts['costs'];
        $consumption = $facts['consumption'];
        $euros = fn (float $amount) => '€ '.number_format($amount, 0, ',', '.');

        // The costs of the fuel by the kilometer go with the consumption: what
        // the refuelings of a month cost depends on when they happen to fall.
        $perKm = isset($costs['fuel_per_km_eur'])
            ? str_replace(':amount', '€ '.number_format($costs['fuel_per_km_eur'], 2, ',', '.'), $this->compare(
                $costs['fuel_per_km_eur'],
                $costs['average_fuel_per_km_eur'] ?? null,
                [__(':amount per km, :percent above average'), __(':amount per km, :percent below average'), __(':amount per km, as usual')],
                true,
            )['comparison'] ?? __(':amount per km'))
            : null;

        return array_values(array_filter([
            $distance === null ? null : [
                'key' => 'distance',
                'label' => __('Distance'),
                'icon' => 'ic:baseline-route',
                'value' => ($distance['estimated'] ? '± ' : '').number_format($distance['km'], 0, ',', '.').' km',
                ...$this->compare($distance['km'], $distance['expected_km'] ?? null, [__(':percent more than usual'), __(':percent less than usual'), __('As usual')], false),
                'details' => $distance['estimated'] ? [__('Estimated from your odometer readings')] : [],
            ],
            [
                'key' => 'costs',
                'label' => __('Costs'),
                'icon' => 'mdi:cash-multiple',
                'value' => $euros($costs['total_eur']),
                // A full month against the average month, part of one against
                // the average up to the same day of the month.
                ...($facts['period']['kind'] === VehicleSummary::PREVIOUS_MONTH
                    ? $this->compare($costs['total_eur'], $costs['average_month_total_eur'] ?? null, [__('Total :percent above average'), __('Total :percent below average'), __('Total as usual')], true)
                    : $this->compare($costs['total_eur'], $costs['average_to_date_total_eur'] ?? null, [__('So far :percent above average'), __('So far :percent below average'), __('So far as usual')], true)),
                'details' => collect($costs['incidental_eur'] ?? [])->map(fn (float $amount, string $type) => __($type).' '.$euros($amount))->values()->all(),
            ],
            $consumption === null ? null : [
                'key' => 'consumption',
                'label' => __('Consumption'),
                'icon' => 'mdi:gauge',
                'value' => number_format($consumption['value'], 1, ',', '.').' '.($consumption['unit'] ?? ''),
                ...$this->compare($consumption['value'], $consumption['average'] ?? null, [__(':percent above average'), __(':percent below average'), __('As usual')], true),
                'details' => array_filter([$perKm]),
            ],
        ]));
    }

    /**
     * A figure against what is usual: more, less, or about the same.
     *
     * @param  array{0: string, 1: string, 2: string}  $texts  For more, for less and for as usual.
     * @param  bool  $moreIsWorse  Whether more is shown as a warning, and less as good.
     * @return array{comparison: string|null, tone: string}
     */
    private function compare(float|int $value, float|int|null $usual, array $texts, bool $moreIsWorse): array
    {
        if ($usual === null || $usual <= 0) {
            return ['comparison' => null, 'tone' => 'neutral'];
        }

        $percent = (int) round(($value / $usual - 1) * 100);

        if (abs($percent) < config('monthly_summary.as_usual_within')) {
            return ['comparison' => $texts[2], 'tone' => 'neutral'];
        }

        $more = $percent > 0;

        return [
            'comparison' => str_replace(':percent', abs($percent).'%', $texts[$more ? 0 : 1]),
            'tone' => $moreIsWorse ? ($more ? 'warning' : 'success') : 'neutral',
        ];
    }

    /**
     * @param  list<array{kind: string, date: string, country?: string, component?: string, types?: list<string>}>  $upcoming
     * @return list<array<string, mixed>>
     */
    private function upcoming(array $upcoming): array
    {
        $labels = [
            'mot' => __('MOT'),
            'maintenance' => __('Maintenance'),
            'insurance' => __('Insurance'),
            'road_tax' => __('Road tax'),
            'vignette' => __('Vignette'),
            'environmental_sticker' => __('Environmental sticker'),
        ];

        return collect($upcoming)
            ->take(config('monthly_summary.upcoming'))
            ->map(function (array $item) use ($labels) {
                $date = Carbon::parse($item['date']);
                $country = isset($item['country']) ? config("countries.{$item['country']}.name", $item['country']) : null;
                $component = isset($item['component']) ? config("maintenance_plan.components.{$item['component']}") : null;
                $label = match (true) {
                    $component !== null => __($component['label']),
                    isset($item['types']) => Vehicle::intervalLabel($item['types']),
                    default => $labels[$item['kind']].($country ? ' '.$country : ''),
                };

                return [
                    'key' => $item['kind'].($item['country'] ?? '').($item['component'] ?? '').implode(',', $item['types'] ?? []),
                    'icon' => $component['icon'] ?? self::ICONS[$item['kind']],
                    'label' => $label,
                    'date' => $date->isoFormat('D MMMM'),
                    'relative' => $date->isToday() ? __('Today') : $date->diffForHumans(today(), ['syntax' => Carbon::DIFF_RELATIVE_TO_NOW]),
                    'url' => route(self::ROUTES[$item['kind']]),
                ];
            })
            ->values()
            ->all();
    }
}
