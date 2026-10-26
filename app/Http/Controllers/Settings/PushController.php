<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\Vehicle;
use App\Services\Push\PushSender;
use App\Services\Push\PushTiming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Push notifications: the devices of the user that receive them, and per
 * vehicle what is sent when (changed in the form of the vehicle).
 */
class PushController extends Controller
{
    public function edit(Request $request, PushTiming $timing): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'publicKey' => config('push.vapid.public_key'),
            'devices' => $user->pushSubscriptions()->latest()->get()->map(fn (PushSubscription $subscription) => [
                'id' => $subscription->id,
                'endpoint' => $subscription->endpoint,
                'name' => $this->deviceName($subscription->user_agent),
                'since' => $subscription->created_at->isoFormat('D MMMM YYYY'),
                'lastUsed' => $subscription->last_used_at?->isoFormat('D MMMM YYYY HH:mm'),
            ])->all(),
            'vehicles' => $user->vehicles()->orderBy('created_at')->get()->map(function (Vehicle $vehicle) use ($timing) {
                $preferences = $vehicle->pushPreferences();

                return [
                    'id' => $vehicle->id,
                    'name' => trim($vehicle->full_name.' ('.$vehicle->license_plate.')'),
                    'kinds' => collect(['critical', 'warning', 'info', 'tips', 'summary'])->filter(fn (string $kind) => $preferences[$kind])->values()->all(),
                    'moment' => $timing->moment($vehicle),
                    'momentIsUsual' => $preferences['moment'] === null,
                    'quietFrom' => $preferences['quiet_from'],
                    'quietUntil' => $preferences['quiet_until'],
                    'editUrl' => route('vehicles.edit', $vehicle),
                ];
            })->all(),
        ]);
    }

    /**
     * The browser subscribed this device, or renewed its subscription.
     */
    public function store(Request $request): HttpResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ]);

        // A device belongs to whoever logged in on it last.
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => PushSubscription::hash($data['endpoint'])], [
            'user_id' => $request->user()->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        return response()->noContent();
    }

    /**
     * The device no longer receives push notifications.
     */
    public function destroy(Request $request): HttpResponse|RedirectResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        $request->user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hash($data['endpoint']))->delete();

        // Another device, removed from the settings page.
        return $request->hasHeader('X-Inertia') ? back() : response()->noContent();
    }

    public function test(Request $request, PushSender $sender): RedirectResponse
    {
        $reached = $sender->test($request->user());

        Inertia::flash('toast', $reached > 0
            ? ['type' => 'success', 'message' => trans_choice('A test notification was sent to :count device.|A test notification was sent to :count devices.', $reached, ['count' => $reached])]
            : ['type' => 'error', 'message' => __('No device could be reached. Turn push notifications on again on this device.')]);

        return back();
    }

    /**
     * Which browser on which system, to tell the devices apart.
     */
    private function deviceName(?string $userAgent): string
    {
        $agent = (string) $userAgent;
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => __('Browser'),
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : __(':browser on :system', ['browser' => $browser, 'system' => $system]);
    }
}
