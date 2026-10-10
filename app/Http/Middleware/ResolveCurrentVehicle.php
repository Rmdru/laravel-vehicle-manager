<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentVehicle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentVehicle
{
    public const SESSION_KEY = 'current_vehicle_id';

    /**
     * Make the vehicle the user is working with available for the request:
     * the one selected in the session, or else their most recent one.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $vehicle = null;

        if ($user) {
            $selected = $request->session()->get(self::SESSION_KEY);

            $vehicle = ($selected ? $user->vehicles()->find($selected) : null)
                ?? $user->defaultVehicle();
        }

        CurrentVehicle::set($vehicle);

        return $next($request);
    }
}
