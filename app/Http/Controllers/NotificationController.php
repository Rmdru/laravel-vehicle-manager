<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VehicleNotification;
use App\Services\NotificationCenter;
use App\Support\CurrentVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * The notifications of the current vehicle: the ones that apply, the
     * dismissed ones, and the ones that no longer apply.
     */
    public function index(Request $request, NotificationCenter $notifications): Response
    {
        // Looked at: the bell no longer counts them.
        $notifications->seen($request->user());

        return Inertia::render('Notifications', [
            'notifications' => $notifications->overview(CurrentVehicle::get()),
        ]);
    }

    /**
     * The bell was opened: it counts the notifications that are new after this.
     */
    public function seen(Request $request, NotificationCenter $notifications): RedirectResponse
    {
        $notifications->seen($request->user());

        return back();
    }

    /**
     * Hides a notification from "Needs attention" until it changes.
     */
    public function dismiss(Request $request, VehicleNotification $notification, NotificationCenter $notifications): RedirectResponse
    {
        $this->authorizeFor($request, $notification);
        $notifications->dismiss($notification);

        return back();
    }

    /**
     * Shows a dismissed notification under "Needs attention" again.
     */
    public function restore(Request $request, VehicleNotification $notification, NotificationCenter $notifications): RedirectResponse
    {
        $this->authorizeFor($request, $notification);
        $notifications->restore($notification);

        return back();
    }

    private function authorizeFor(Request $request, VehicleNotification $notification): void
    {
        abort_unless($notification->vehicle?->user_id === $request->user()->id, 404);
    }
}
