<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\FuelAdviceService;
use App\Support\CurrentVehicle;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

class FuelAdviceController extends Controller
{
    /**
     * Generates the advice of the current vehicle in the current language, for
     * whatever its status: the daily run only covers drivable vehicles.
     */
    public function store(FuelAdviceService $advices): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();
        $locale = app()->getLocale();

        if (! $advices->canGenerate($vehicle, $locale)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('The fuel advice has just been generated.')]);

            return back();
        }

        try {
            $advice = $advices->generate($vehicle, $locale);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('The fuel advice could not be generated. Please try again later.')]);

            return back();
        }

        Inertia::flash('toast', $advice
            ? ['type' => 'success', 'message' => __('The fuel advice has been generated.')]
            : ['type' => 'info', 'message' => __('There are no fuel prices to base an advice on.')]);

        return back();
    }
}
