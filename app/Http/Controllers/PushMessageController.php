<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveCurrentVehicle;
use App\Models\PushMessage;
use App\Models\Vehicle;
use App\Services\MaintenancePlanService;
use App\Services\NotificationCenter;
use App\Support\CurrentVehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * What a push notification does on the device: opening the app at its
 * subject, or one of its buttons.
 */
class PushMessageController extends Controller
{
    /**
     * Tapped: the app opens with the vehicle of the message, at its subject.
     */
    public function open(Request $request, PushMessage $message): RedirectResponse
    {
        abort_unless($message->vehicle?->user_id === $request->user()->id, 404);

        $request->session()->put(ResolveCurrentVehicle::SESSION_KEY, $message->vehicle_id);

        if ($request->query('to') === 'notifications' || $message->url === null) {
            return redirect()->route($request->query('to') === 'notifications' ? 'notifications.index' : 'dashboard');
        }

        // Only its path: the url may have been made on the command line, with the host of APP_URL.
        $url = parse_url($message->url);

        return redirect(($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : ''));
    }

    /**
     * A button of the notification, from the service worker: the app does not
     * open, so the signed link stands in for the login.
     */
    public function action(PushMessage $message, string $action, NotificationCenter $notifications, MaintenancePlanService $plan): Response
    {
        $notification = $message->notification;
        $vehicle = $message->vehicle;

        abort_if($notification === null || $vehicle === null || $notification->resolved_at !== null, 404);

        // The notifications are stored as they are shown: in the language of the owner.
        App::setLocale($vehicle->user->language ?: config('app.locale'));

        match ($action) {
            'dismiss' => $notifications->dismiss($notification),
            'done' => $this->done($notification->key, $vehicle, $plan, $notifications),
            default => abort(404),
        };

        return response()->noContent();
    }

    private function done(string $key, Vehicle $vehicle, MaintenancePlanService $plan, NotificationCenter $notifications): void
    {
        $component = Str::after($key, 'maintenance_plan:');

        abort_unless(str_starts_with($key, 'maintenance_plan:') && $plan->components($vehicle)->has($component), 404);

        CurrentVehicle::set($vehicle);
        $plan->done($vehicle, $component);
        $notifications->sync($vehicle->fresh());
    }
}
