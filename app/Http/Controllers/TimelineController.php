<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TimelineService;
use App\Support\CurrentVehicle;
use Inertia\Inertia;
use Inertia\Response;

class TimelineController extends Controller
{
    public function __invoke(TimelineService $timeline): Response
    {
        $vehicle = CurrentVehicle::get();

        return Inertia::render('Timeline', [
            'history' => $timeline->history($vehicle),
            'predictions' => $timeline->predictions($vehicle),
        ]);
    }
}
