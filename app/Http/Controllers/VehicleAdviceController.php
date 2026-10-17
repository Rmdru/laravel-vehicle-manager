<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VehicleTip;
use App\Services\VehicleAdviceService;
use App\Support\CurrentVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class VehicleAdviceController extends Controller
{
    /**
     * Generates the advice of the current vehicle in the current language, for
     * whatever its status: the daily run only covers drivable vehicles.
     */
    public function store(VehicleAdviceService $advices): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();
        $locale = app()->getLocale();

        if (! $advices->canGenerate($vehicle, $locale)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('The tips have just been generated.')]);

            return back();
        }

        try {
            $advices->generate($vehicle, $locale);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('The tips could not be generated. Please try again later.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The tips have been generated.')]);

        return back();
    }

    /**
     * All tips of the current vehicle: the current ones, and the ones kept or done.
     */
    public function index(VehicleAdviceService $advices): Response
    {
        return Inertia::render('Tips', [
            'tips' => $advices->overview(CurrentVehicle::get()),
        ]);
    }

    /**
     * Marks a tip as done, kept or not relevant.
     */
    public function status(Request $request, VehicleAdviceService $advices): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:60'],
            'status' => ['required', Rule::in(VehicleTip::STATUSES)],
        ]);

        if (! $advices->handle(CurrentVehicle::get(), $validated['key'], $validated['status'])) {
            throw ValidationException::withMessages(['key' => __('This tip no longer exists.')]);
        }

        return back();
    }
}
