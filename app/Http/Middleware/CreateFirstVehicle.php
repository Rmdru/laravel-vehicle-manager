<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentVehicle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CreateFirstVehicle
{
    /**
     * Send users without a vehicle to the form to create their first one.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! CurrentVehicle::get()) {
            return redirect()->route('vehicles.create');
        }

        return $next($request);
    }
}
