<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Poi;
use App\Services\FuelComparison;
use App\Services\TripPlanner\StationMap;
use App\Support\CurrentVehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The map of gas stations and charging stations, beside the trip planner.
 */
class StationMapController extends Controller
{
    public function index(Request $request): Response
    {
        $vehicle = CurrentVehicle::get();
        $fuelTypes = trans('fuel_types');

        return Inertia::render('Stations', [
            'dataUrl' => route('stations.data'),
            'pricesUrl' => route('stations.prices'),
            'logoUrl' => route('trips.logo'),
            'shields' => TripController::shields(),
            // The fuel types to filter on, in the language of the user.
            'fuelTypes' => collect(array_keys(StationMap::FUELS))
                ->map(fn (string $type) => ['value' => $type, 'label' => is_array($fuelTypes) ? ($fuelTypes[$type] ?? $type) : $type])
                ->all(),
            'connectors' => array_keys(Poi::CONNECTORS),
            // The fuels of the vehicle, as the filter starts with; an electric vehicle starts at the chargers.
            'vehicleFuelTypes' => array_values(array_intersect((array) $vehicle?->fuel_types, array_keys(StationMap::FUELS))),
            'electric' => $vehicle?->powertrain === 'electricity',
            // Where to open the map, such as at home from the dashboard; else where it was left.
            'focus' => is_numeric($request->query('latitude')) && is_numeric($request->query('longitude'))
                ? ['latitude' => (float) $request->query('latitude'), 'longitude' => (float) $request->query('longitude')]
                : null,
            'stationsFrom' => StationMap::STATIONS_FROM,
            'livePricesFrom' => StationMap::LIVE_PRICES_FROM,
        ]);
    }

    /**
     * The clusters or the stations within the bounds of the map.
     */
    public function data(Request $request, StationMap $map, FuelComparison $comparison): JsonResponse
    {
        $validated = $this->validated($request);
        $bounds = $validated['bounds'];
        $zoom = $validated['zoom'];

        $loading = $zoom >= StationMap::FETCH_FROM && $map->fetchMissing($bounds);

        if ($zoom < StationMap::STATIONS_FROM) {
            return response()->json([
                'mode' => 'clusters',
                'clusters' => $map->clusters($bounds, $zoom, $validated['kinds']),
                'loading' => $loading,
            ]);
        }

        $result = $map->stations($bounds, $validated, $request->user());
        $countries = collect($result['stations'])->where('kind', 'fuel')->pluck('country')->filter()->unique()->values()->all();

        $vehicle = CurrentVehicle::get();

        return response()->json([
            'mode' => 'stations',
            ...$result,
            // To tell of a gas station how much less it is than the usual one, and whether a detour pays off.
            'comparison' => $vehicle !== null ? $comparison->forMap($vehicle) : null,
            'averages' => $map->averages($countries, $validated['fuels']),
            'loading' => $loading,
        ]);
    }

    /**
     * The prices of a source that is asked per area (Germany, Austria), by station.
     */
    public function prices(Request $request, StationMap $map): JsonResponse
    {
        $validated = $this->validated($request);

        if ($validated['zoom'] < StationMap::LIVE_PRICES_FROM) {
            return response()->json(['prices' => (object) []]);
        }

        $primary = $request->validate(['primary' => ['nullable', Rule::in(array_keys(StationMap::FUELS))]])['primary'] ?? null;

        return response()->json(['prices' => (object) $map->livePrices($validated['bounds'], $validated['fuels'], $primary)]);
    }

    /**
     * @return array{bounds: array{0: float, 1: float, 2: float, 3: float}, zoom: int, kinds: list<string>, fuels: list<string>, connectors: list<string>, priced: bool}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'south' => ['required', 'numeric', 'between:-90,90'],
            'west' => ['required', 'numeric', 'between:-180,180'],
            'north' => ['required', 'numeric', 'between:-90,90', 'gte:south'],
            'east' => ['required', 'numeric', 'between:-180,180'],
            'zoom' => ['required', 'integer', 'between:0,22'],
            'kinds' => ['array'],
            'kinds.*' => [Rule::in(array_keys(StationMap::KINDS))],
            'fuels' => ['array'],
            'fuels.*' => [Rule::in(array_keys(StationMap::FUELS))],
            'connectors' => ['array'],
            'connectors.*' => [Rule::in(array_keys(Poi::CONNECTORS))],
            'priced' => ['boolean'],
        ]);

        return [
            'bounds' => [(float) $validated['south'], (float) $validated['west'], (float) $validated['north'], (float) $validated['east']],
            'zoom' => (int) $validated['zoom'],
            'kinds' => array_values($validated['kinds'] ?? []),
            'fuels' => array_values($validated['fuels'] ?? []),
            'connectors' => array_values($validated['connectors'] ?? []),
            'priced' => (bool) ($validated['priced'] ?? false),
        ];
    }
}
