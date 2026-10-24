<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PowertrainComparisonService;
use App\Support\CurrentVehicle;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PowertrainComparisonController extends Controller
{
    /**
     * Has the alternatives of the current vehicle chosen again, after the
     * response: it takes a while.
     */
    public function store(PowertrainComparisonService $comparisons): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();
        $locale = app()->getLocale();

        if (! $comparisons->canChoose($vehicle, $locale)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('The alternatives have just been chosen.')]);

            return back();
        }

        $comparisons->chooseLater($vehicle, $locale, force: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The alternatives are being chosen.')]);

        return back();
    }

    /**
     * Calculates the costs again with the prices and the records of now.
     */
    public function refresh(PowertrainComparisonService $comparisons): RedirectResponse
    {
        $comparisons->forgetCosts(CurrentVehicle::get(), app()->getLocale());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The costs have been calculated again.')]);

        return back();
    }
}
