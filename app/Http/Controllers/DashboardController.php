<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\FuelAdviceService;
use App\Support\Cost;
use App\Support\CurrentVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Minutes between two refreshes of the fuel prices, at least. */
    private const REFRESH_FUEL_PRICES_EVERY = 5;

    public function __invoke(Request $request, DashboardService $dashboard, FuelAdviceService $fuelAdvice): Response
    {
        $vehicle = CurrentVehicle::get();

        $filters = $request->validate([
            'dashboard_start' => ['nullable', 'date'],
            'dashboard_end' => ['nullable', 'date', 'after_or_equal:dashboard_start'],
            'cost_types' => ['nullable', 'array'],
            'cost_types.*' => ['string', Rule::in(array_keys(Cost::types($vehicle)))],
        ]);

        $start = $filters['dashboard_start'] ?? null;
        $end = $filters['dashboard_end'] ?? null;
        $costTypes = array_values($filters['cost_types'] ?? []);

        $usesFuel = ! in_array($vehicle->powertrain, ['electricity', 'hydrogen'], true);

        return Inertia::render('Dashboard', [
            'filters' => ['start' => $start, 'end' => $end, 'costTypes' => $costTypes],
            'costTypeOptions' => collect(Cost::types($vehicle))->map(fn (array $type, string $name) => __($name))->all(),
            'usesFuel' => $usesFuel,
            'refreshFuelPricesUrl' => route('fuel-prices.refresh'),
            // Deferred: the notifications can involve slow lookups at external services.
            'notifications' => Inertia::defer(fn () => $dashboard->notifications($vehicle), 'status'),
            'statuses' => Inertia::defer(fn () => $dashboard->statuses($vehicle), 'status'),
            'stats' => Inertia::defer(fn () => $dashboard->stats($vehicle, $start, $end), 'statistics'),
            'fuelPricesAbroad' => Inertia::defer(fn () => $dashboard->fuelPricesAbroad($vehicle), 'fuel'),
            'fuelAdvice' => Inertia::defer(fn () => $usesFuel ? $fuelAdvice->forDashboard($vehicle) : null, 'fuel'),
            'fuelPricesChart' => Inertia::defer(fn () => $dashboard->fuelPricesChart($vehicle, $start, $end), 'fuel'),
            'fuelUsageByType' => Inertia::defer(fn () => $dashboard->fuelUsageByType($vehicle, $start, $end), 'fuel'),
            'topGasStations' => Inertia::defer(fn () => $dashboard->topGasStations($vehicle, $start, $end), 'fuel'),
            'averageMonthlyCosts' => Inertia::defer(fn () => $dashboard->averageMonthlyCostsByType($vehicle, $start, $end, $costTypes), 'costs'),
            'costsChart' => Inertia::defer(fn () => $dashboard->costsChart($vehicle, $start, $end, $costTypes), 'costs'),
            'latestCosts' => Inertia::defer(fn () => $dashboard->latestCosts($vehicle, $start, $end, $costTypes), 'costs'),
        ]);
    }

    public function refreshFuelPrices(): RedirectResponse
    {
        // Asked for by hand: the detours of the current vehicle are calculated
        // whatever its status, while the daily import only does drivable ones.
        $vehicle = CurrentVehicle::get()->id;

        // The import calls several external services, so it runs after the response
        // has been sent, and not again while it is running or has only just run. The
        // detours of the vehicle then come from the prices that were just imported.
        if (! Cache::add('fuel-prices:refreshing', true, now()->addMinutes(self::REFRESH_FUEL_PRICES_EVERY))) {
            defer(fn () => Artisan::call('import:fuel-prices', ['--vehicle' => $vehicle, '--detours-only' => true]));

            Inertia::flash('toast', ['type' => 'success', 'message' => __('The fuel prices have just been refreshed.')]);

            return back();
        }

        defer(fn () => Artisan::call('import:fuel-prices', ['--vehicle' => $vehicle]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The fuel prices are being refreshed.')]);

        return back();
    }
}
