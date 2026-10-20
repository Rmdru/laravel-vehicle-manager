<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Ai\Agents\TripAdvisor;
use App\Ai\ApiKeys;
use App\Models\Trip;
use App\Models\TripWaypoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Locale;
use RuntimeException;
use Throwable;

/**
 * Travel tips for a planned trip from an AI model, generated when the driver
 * asks for them.
 */
class TripTips
{
    /** The language the tips are written in, by locale: addressed as the interface does. */
    private const LANGUAGES = [
        'en' => 'English',
        'nl' => 'Dutch, addressing the driver informally ("je")',
        'de' => 'German, addressing the driver formally ("Sie")',
        'fr' => 'French, addressing the driver formally ("vous")',
        'it' => 'Italian, addressing the driver informally ("tu")',
        'es' => 'Spanish, addressing the driver informally ("tú")',
    ];

    /** Tips at most. */
    private const TIPS = 6;

    /** Minutes before the tips of a trip can be generated again. */
    public const AGAIN_AFTER = 10;

    public function canGenerate(Trip $trip): bool
    {
        $generated = $trip->tips['generated_at'] ?? null;

        return $generated === null || CarbonImmutable::parse($generated)->lt(now()->subMinutes(self::AGAIN_AFTER));
    }

    /**
     * Generates the tips of the route the driver chose, and keeps them with the trip.
     *
     * @throws Throwable When the AI model gives no answer.
     */
    public function generate(Trip $trip, string $locale): array
    {
        $facts = $this->facts($trip);
        $response = $this->ask($facts, self::LANGUAGES[$locale] ?? 'English');

        $tips = [
            'locale' => $locale,
            'generated_at' => now()->toIso8601String(),
            'model' => $response->meta->model ?? config('vehicle_advice.model'),
            'items' => collect((array) ($response['tips'] ?? []))
                ->filter(fn ($tip) => is_array($tip) && filled($tip['title'] ?? null))
                ->map(fn (array $tip) => [
                    'category' => array_key_exists((string) ($tip['category'] ?? ''), TripAdvisor::CATEGORIES) ? $tip['category'] : 'comfort',
                    'title' => Str::limit(trim((string) $tip['title']), 90),
                    'body' => Str::limit(trim(strip_tags((string) ($tip['body'] ?? ''))), 600),
                ])
                ->take(self::TIPS)
                ->values()
                ->all(),
        ];

        $trip->update(['tips' => $tips]);

        return $tips;
    }

    /**
     * What the model gets to know of the trip: the route the driver chose,
     * without anything that tells who the driver is.
     *
     * @return array<string, mixed>
     */
    public function facts(Trip $trip): array
    {
        $route = $trip->plan['routes'][$trip->selected_route] ?? $trip->plan['routes'][0] ?? [];
        $timezone = config('trip_planner.timezone');
        $local = fn (?string $time) => $time ? CarbonImmutable::parse($time)->setTimezone($timezone)->format('Y-m-d H:i (l)') : null;
        $country = fn (string $code) => extension_loaded('intl') ? (Locale::getDisplayRegion('-'.$code, 'en') ?: $code) : $code;
        $vehicle = $trip->vehicle;
        $stops = collect($route['stops'] ?? []);

        return array_filter([
            'departure' => $local($route['departure_at'] ?? null),
            'arrival' => $local($route['arrival_at'] ?? null),
            'destinations' => $trip->waypoints->map(fn (TripWaypoint $waypoint) => trim(explode(',', $waypoint->label)[0]))->all(),
            'distance_km' => isset($route['distance']) ? (int) round($route['distance'] / 1000) : null,
            'driving_hours' => isset($route['drive_seconds']) ? round($route['drive_seconds'] / 3600, 1) : null,
            'countries' => array_map($country, $route['countries'] ?? []),
            'main_roads' => array_column($route['roads'] ?? [], 'label'),
            'toll_km' => isset($route['toll_distance']) ? (int) round($route['toll_distance'] / 1000) : null,
            'vignette_countries' => array_map($country, $route['vignettes'] ?? []),
            'planned_stops' => $stops->where('type', 'stop')->count(),
            'nights_on_the_way' => $stops->filter(fn (array $stop) => in_array('hotel', $stop['needs'] ?? [], true))->count(),
            'traffic' => collect($route['traffic'] ?? [])->map(fn (array $incident) => trim($incident['category'].' '.($incident['road'] ?? '').' '.($incident['description'] ?? '')))->unique()->take(10)->values()->all(),
            'notices' => array_column(TripNotices::translate($route['notices'] ?? []), 'message'),
            'vehicle' => $vehicle ? array_filter([
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'powertrain' => $vehicle->powertrain,
                'fuel_types' => $vehicle->fuel_types ?: null,
                'age_years' => $vehicle->construction_date ? (int) $vehicle->construction_date->diffInYears(today()) : null,
                'country_of_registration' => $vehicle->country_registration,
            ]) : null,
        ], fn ($value) => $value !== null && $value !== []);
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
        $failure = new RuntimeException('There is no API key.');

        foreach (ApiKeys::shuffled(config('vehicle_advice.provider')) as $number => $key) {
            $advisor = new TripAdvisor($language, self::TIPS, $key);

            try {
                $response = $advisor->prompt($advisor->question($facts));

                if (! $response instanceof StructuredAgentResponse) {
                    throw new RuntimeException('The trip advisor gave no structured answer.');
                }

                return $response;
            } catch (Throwable $exception) {
                $failure = $exception;

                Log::warning('Trip tips failed, trying the next key', [
                    'key' => $key === null ? null : $number + 1,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        throw $failure;
    }
}
