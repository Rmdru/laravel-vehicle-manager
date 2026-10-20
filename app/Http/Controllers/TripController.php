<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\PlanTrip;
use App\Models\Poi;
use App\Models\Trip;
use App\Models\TripWaypoint;
use App\Services\TripPlanner\TomTomGeocodingService;
use App\Services\TripPlanner\TripNotices;
use App\Services\TripPlanner\TripTips;
use App\Support\CurrentVehicle;
use App\Support\GasStationLogo;
use App\Support\MapShields;
use App\Support\Roads;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class TripController extends Controller
{
    /**
     * The trips of the vehicle chosen at the top left, the latest first.
     */
    public function index(Request $request): Response
    {
        $trips = $request->user()->trips()
            ->where('vehicle_id', CurrentVehicle::get()?->id)
            ->with(['vehicle', 'waypoints'])
            ->orderByDesc('time_at')
            ->get()
            ->map(fn (Trip $trip) => [
                'id' => $trip->id,
                'name' => $this->name($trip),
                'vehicle' => $trip->vehicle?->full_name,
                'timeMode' => $trip->time_mode,
                'timeAt' => $this->local($trip->time_at)->toIso8601String(),
                'status' => $trip->status,
                'distance' => $trip->plan['routes'][$trip->selected_route]['distance'] ?? null,
                'totalSeconds' => isset($trip->plan['routes'][$trip->selected_route])
                    ? $trip->plan['routes'][$trip->selected_route]['drive_seconds'] + $trip->plan['routes'][$trip->selected_route]['stop_seconds']
                    : null,
                'url' => route('trips.show', $trip),
            ]);

        return Inertia::render('Trips/Index', [
            'trips' => $trips,
            'createUrl' => route('trips.create'),
            'timezone' => config('trip_planner.timezone'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $start = $user->latitude !== null && $user->longitude !== null
            ? ['label' => (string) $user->city, 'latitude' => (float) $user->latitude, 'longitude' => (float) $user->longitude, 'stayMinutes' => 0]
            : null;

        return $this->planner(null, [
            'name' => '',
            'timeMode' => 'departure',
            'timeAt' => now(config('trip_planner.timezone'))->addHour()->startOfHour()->format('Y-m-d\TH:i'),
            'fuelLevel' => 100,
            'waypoints' => [$start, null],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $trip = DB::transaction(function () use ($request) {
            // For the vehicle chosen at the top left.
            $trip = $request->user()->trips()->create([
                ...$this->validated($request),
                'vehicle_id' => CurrentVehicle::get()?->id,
                'status' => Trip::STATUS_PLANNING,
            ]);
            $this->storeWaypoints($trip, $request->input('waypoints'));

            return $trip;
        });

        PlanTrip::dispatch($trip);

        return to_route('trips.show', $trip);
    }

    public function show(Request $request, Trip $trip): Response
    {
        $this->authorizeTrip($request, $trip);
        $trip->load('waypoints');

        return $this->planner($trip, [
            'name' => (string) $trip->name,
            'timeMode' => $trip->time_mode,
            'timeAt' => $this->local($trip->time_at)->format('Y-m-d\TH:i'),
            'fuelLevel' => $trip->fuel_level,
            'waypoints' => $trip->waypoints->map(fn (TripWaypoint $waypoint) => [
                'label' => $waypoint->label,
                'latitude' => $waypoint->latitude,
                'longitude' => $waypoint->longitude,
                'stayMinutes' => $waypoint->stay_minutes,
            ])->all(),
        ]);
    }

    public function update(Request $request, Trip $trip): RedirectResponse
    {
        $this->authorizeTrip($request, $trip);

        DB::transaction(function () use ($request, $trip) {
            $trip->update([
                ...$this->validated($request),
                'status' => Trip::STATUS_PLANNING,
                'error' => null,
                'plan_version' => $trip->plan_version + 1,
            ]);
            $trip->waypoints()->delete();
            $this->storeWaypoints($trip, $request->input('waypoints'));
        });

        PlanTrip::dispatch($trip);

        return to_route('trips.show', $trip);
    }

    /**
     * The driver chooses a place to stop at, or not to stop at: the trip is
     * planned again around it.
     */
    public function stops(Request $request, Trip $trip): RedirectResponse
    {
        $this->authorizeTrip($request, $trip);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['pin', 'unpin', 'exclude', 'reset'])],
            'poi' => ['required_unless:action,reset', 'integer'],
        ]);

        $poi = (int) ($validated['poi'] ?? 0);
        $pinned = array_map('intval', (array) $trip->pinned_stops);
        $excluded = array_map('intval', (array) $trip->excluded_stops);

        [$pinned, $excluded] = match ($validated['action']) {
            'pin' => [[...array_diff($pinned, [$poi]), $poi], array_diff($excluded, [$poi])],
            'unpin' => [array_diff($pinned, [$poi]), $excluded],
            'exclude' => [array_diff($pinned, [$poi]), [...array_diff($excluded, [$poi]), $poi]],
            default => [[], []],
        };

        $trip->update([
            'pinned_stops' => array_values($pinned),
            'excluded_stops' => array_values($excluded),
            'status' => Trip::STATUS_PLANNING,
            'error' => null,
            'plan_version' => $trip->plan_version + 1,
        ]);

        PlanTrip::dispatch($trip);

        return back();
    }

    /**
     * Travel tips of an AI model for the route the driver chose.
     */
    public function tips(Request $request, Trip $trip, TripTips $tips): RedirectResponse
    {
        $this->authorizeTrip($request, $trip);

        if ($trip->status !== Trip::STATUS_PLANNED) {
            return back();
        }

        if (! $tips->canGenerate($trip)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('The tips have just been generated.')]);

            return back();
        }

        try {
            $tips->generate($trip, app()->getLocale());
        } catch (Throwable $exception) {
            report($exception);
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The tips could not be generated. Please try again later.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The tips have been generated.')]);

        return back();
    }

    /**
     * Remembers which of the planned routes the user chose.
     */
    public function selectRoute(Request $request, Trip $trip): RedirectResponse
    {
        $this->authorizeTrip($request, $trip);

        $validated = $request->validate([
            'route' => ['required', 'integer', 'min:0', 'max:'.max(0, count($trip->plan['routes'] ?? []) - 1)],
        ]);

        $trip->update(['selected_route' => $validated['route']]);

        return back();
    }

    public function destroy(Request $request, Trip $trip): RedirectResponse
    {
        $this->authorizeTrip($request, $trip);
        $trip->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The trip has been deleted.')]);

        return to_route('trips.index');
    }

    /**
     * Places that match what is typed so far, for picking a start or a destination.
     */
    public function geocode(Request $request, TomTomGeocodingService $geocoding): JsonResponse
    {
        $validated = $request->validate(['query' => ['required', 'string', 'min:3', 'max:100']]);
        $user = $request->user();

        return response()->json($geocoding->search(
            $validated['query'],
            app()->getLocale(),
            $user->latitude !== null ? (float) $user->latitude : null,
            $user->longitude !== null ? (float) $user->longitude : null,
        ));
    }

    /**
     * The logo of the brand of a gas station: looked up when a place on the
     * map is opened, as it may have to be fetched first.
     */
    public function logo(Request $request): SymfonyResponse
    {
        $validated = $request->validate(['brand' => ['required', 'string', 'max:100'], 'known' => ['nullable', 'boolean']]);
        // Only a logo of the brand itself: the page shows an icon of its own instead.
        $url = $request->boolean('known') ? GasStationLogo::known($validated['brand']) : GasStationLogo::url($validated['brand']);

        abort_if($url === null, 404);

        if (str_starts_with($url, 'data:image/svg+xml;base64,')) {
            return response(base64_decode(substr($url, 26)), 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'public, max-age=604800',
            ]);
        }

        return redirect($url);
    }

    /**
     * The address at a point, for starting at the current location.
     */
    public function reverseGeocode(Request $request, TomTomGeocodingService $geocoding): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $label = $geocoding->reverse((float) $validated['latitude'], (float) $validated['longitude'], app()->getLocale());

        return response()->json([
            'label' => $label ?? __('Current location'),
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function planner(?Trip $trip, array $form): Response
    {
        $plan = $trip?->plan;
        $routes = collect($plan['routes'] ?? []);
        $poiIds = $routes->flatMap(fn (array $route) => [
            ...($route['pois'] ?? []),
            ...array_column($route['candidates'] ?? [], 'id'),
            ...($route['toll_booths'] ?? []),
        ])->unique()->values()->all();
        $vehicle = $trip ? $trip->vehicle : CurrentVehicle::get();

        return Inertia::render('Trips/Planner', [
            'form' => $form,
            'trip' => $trip ? [
                'id' => $trip->id,
                'name' => $this->name($trip),
                'status' => $trip->status,
                // The part of the planning that is done, while it is being planned.
                'progress' => in_array($trip->status, [Trip::STATUS_PLANNING, Trip::STATUS_ROUTED], true)
                    ? (float) Cache::get(PlanTrip::progressKey($trip), 0)
                    : null,
                'error' => $trip->error ? __($trip->error) : null,
                'selectedRoute' => $trip->selected_route,
                'plan' => $plan ? [
                    ...$plan,
                    'vehicle' => isset($plan['vehicle']) ? [
                        ...$plan['vehicle'],
                        'fuel_type_label' => isset($plan['vehicle']['fuel_type'])
                            ? (trans('fuel_types')[$plan['vehicle']['fuel_type']] ?? $plan['vehicle']['fuel_type'])
                            : null,
                    ] : null,
                    'routes' => array_map(fn (array $route) => [
                        ...array_diff_key($route, ['pois' => true]),
                        'poiIds' => $route['pois'],
                        // What the driver should know, in the language of the user.
                        'notices' => TripNotices::translate($route['notices'] ?? []),
                        // A road number as on the signs, also for a trip planned before that was kept.
                        'traffic' => array_map(fn (array $incident) => [
                            ...$incident,
                            'road_badge' => $incident['road_badge']
                                ?? (filled($incident['road'] ?? null) ? Roads::badgeIn((array) ($route['countries'] ?? []), (string) $incident['road']) : null),
                        ], $route['traffic'] ?? []),
                        // The fuel of each price, as the language of the user names it.
                        'prices' => array_map(fn (array $price) => [
                            ...$price,
                            'fuel_type' => isset($price['fuel_type']) ? (trans('fuel_types')[$price['fuel_type']] ?? $price['fuel_type']) : null,
                        ], $route['prices'] ?? []),
                    ], $plan['routes']),
                ] : null,
                // Stale while a newer version of the trip is being planned.
                'version' => $trip->plan_version,
                'pinned' => array_map('intval', (array) $trip->pinned_stops),
                'excluded' => count((array) $trip->excluded_stops),
                'tips' => $trip->tips,
                'stopsUrl' => route('trips.stops', $trip),
                'tipsUrl' => route('trips.tips', $trip),
                'updateUrl' => route('trips.update', $trip),
                'destroyUrl' => route('trips.destroy', $trip),
                'selectRouteUrl' => route('trips.route', $trip),
            ] : null,
            'pois' => fn () => $this->pois($poiIds),
            'vehicle' => $vehicle?->full_name,
            'vehicleId' => $vehicle?->id,
            // The road numbers on the map, as the road signs of each country show them.
            'shields' => [
                'countries' => MapShields::styles(),
                'european' => ['image' => MapShields::image(MapShields::EUROPEAN), 'text' => MapShields::hex(MapShields::EUROPEAN['color'])],
                'plain' => ['image' => MapShields::image(MapShields::PLAIN), 'text' => MapShields::hex(MapShields::PLAIN['color'])],
                'sprite' => asset('map/shields'),
            ],
            'storeUrl' => route('trips.store'),
            'indexUrl' => route('trips.index'),
            'geocodeUrl' => route('trips.geocode'),
            'reverseGeocodeUrl' => route('trips.reverse-geocode'),
            'logoUrl' => route('trips.logo'),
            'timezone' => config('trip_planner.timezone'),
        ]);
    }

    /**
     * The places shown on the map, with what is known of them.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function pois(array $ids): array
    {
        return collect($ids)
            ->chunk(1000)
            ->flatMap(fn ($chunk) => Poi::whereIn('id', $chunk->all())->get())
            ->map(function (Poi $poi) {
                return [
                    'id' => $poi->id,
                    'kind' => $poi->kind,
                    'name' => $poi->name,
                    'brand' => $poi->brand,
                    'latitude' => $poi->latitude,
                    'longitude' => $poi->longitude,
                    'features' => $poi->features(),
                    'details' => $poi->details(),
                    'services' => $poi->services(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'timeMode' => ['required', Rule::in(Trip::TIME_MODES)],
            'timeAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'fuelLevel' => ['required', 'integer', 'min:5', 'max:100'],
            'waypoints' => ['required', 'array', 'min:2', 'max:10'],
            'waypoints.*' => ['required', 'array'],
            'waypoints.*.label' => ['required', 'string', 'max:255'],
            'waypoints.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'waypoints.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'waypoints.*.stayMinutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ], [
            'waypoints.*.required' => __('Choose a place from the list.'),
            'waypoints.*.latitude.required' => __('Choose a place from the list.'),
        ]);

        return [
            'name' => $validated['name'] ?: null,
            'time_mode' => $validated['timeMode'],
            'time_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['timeAt'], config('trip_planner.timezone'))->utc(),
            'fuel_level' => $validated['fuelLevel'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $waypoints
     */
    private function storeWaypoints(Trip $trip, array $waypoints): void
    {
        $last = count($waypoints) - 1;

        foreach ($waypoints as $position => $waypoint) {
            $trip->waypoints()->create([
                'position' => $position,
                'label' => $waypoint['label'],
                'latitude' => $waypoint['latitude'],
                'longitude' => $waypoint['longitude'],
                // Only a destination along the way has a stay.
                'stay_minutes' => $position > 0 && $position < $last ? (int) ($waypoint['stayMinutes'] ?? 0) : 0,
            ]);
        }
    }

    /**
     * The name of a trip, or else where it goes from and to.
     */
    private function name(Trip $trip): string
    {
        if ($trip->name) {
            return $trip->name;
        }

        $first = $trip->waypoints->first()?->label;
        $last = $trip->waypoints->last()?->label;
        $short = fn (?string $label) => trim(explode(',', (string) $label)[0]);

        return $short($first).' → '.$short($last);
    }

    private function local(mixed $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time)->setTimezone(config('trip_planner.timezone'));
    }

    private function authorizeTrip(Request $request, Trip $trip): void
    {
        abort_unless($trip->user_id === $request->user()->id, 404);
    }
}
