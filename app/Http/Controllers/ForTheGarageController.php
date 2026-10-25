<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GarageListItem;
use App\Services\FindingService;
use App\Services\MaintenancePlanService;
use App\Support\CurrentVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The list for the next visit to the garage: the findings on it and the
 * lines the owner added by themselves, with what else is open or due to add,
 * and as text to send to the garage.
 */
class ForTheGarageController extends Controller
{
    public function index(FindingService $findings, MaintenancePlanService $plan): Response
    {
        $vehicle = CurrentVehicle::get();

        return Inertia::render('ForTheGarage', [
            'vehicle' => [
                'name' => $vehicle->full_name,
                'licensePlate' => $vehicle->license_plate,
                'mileage' => $vehicle->mileage_latest ? number_format((int) $vehicle->mileage_latest, 0, ',', '.').' km' : null,
            ],
            ...$findings->forGarage($vehicle, $plan),
            'lines' => $vehicle->garageListItems()->oldest()->get()
                ->map(fn (GarageListItem $line) => ['id' => $line->id, 'text' => $line->text, 'deleteUrl' => route('for-the-garage.lines.destroy', $line->id)])
                ->all(),
            'linesUrl' => route('for-the-garage.lines.store'),
            'createUrl' => route('findings.create', ['for_garage' => 1]),
            'recordUrl' => route('maintenances.create', ['for_garage' => 1]),
        ]);
    }

    /**
     * Adds a line of the owner's own to the list, without it being a finding.
     */
    public function storeLine(Request $request): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:255']]);

        CurrentVehicle::get()->garageListItems()->create($data);

        return back();
    }

    public function destroyLine(int $line): RedirectResponse
    {
        CurrentVehicle::get()->garageListItems()->findOrFail($line)->delete();

        return back();
    }

    /**
     * Puts a component of the maintenance plan that is due on the list.
     */
    public function plan(string $key, FindingService $findings, MaintenancePlanService $plan): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();

        abort_unless($plan->components($vehicle)->has($key), 404);

        $finding = $findings->addFromPlan($vehicle, $key);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name put on the list for the garage.', ['name' => $finding->title])]);

        return back();
    }
}
