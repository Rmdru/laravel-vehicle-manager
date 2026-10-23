<?php

namespace App\Http\Middleware;

use App\Services\NotificationCenter;
use App\Support\CurrentVehicle;
use App\Support\Navigation;
use App\Support\VehiclePresenter;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'locale' => fn () => app()->getLocale(),
            'locales' => SetLocale::LOCALES,
            't' => fn () => Navigation::translations(),
            'navigation' => fn () => $request->user() ? Navigation::groups() : [],
            'currentVehicle' => fn () => CurrentVehicle::get() ? VehiclePresenter::summary(CurrentVehicle::get()) : null,
            // From what is stored: the notifications are brought up to date elsewhere.
            'notificationBell' => fn () => CurrentVehicle::get() ? app(NotificationCenter::class)->bell(CurrentVehicle::get(), $request->user()) : null,
            'vehicles' => fn () => $request->user()
                ? $request->user()->vehicles()->latest()->get()->map(fn ($vehicle) => [
                    'id' => $vehicle->id,
                    'name' => $vehicle->full_name,
                    'licensePlate' => $vehicle->license_plate,
                    'selectUrl' => route('vehicles.select', $vehicle),
                ])->all()
                : [],
        ];
    }
}
