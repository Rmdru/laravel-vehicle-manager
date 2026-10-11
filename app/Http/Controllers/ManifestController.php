<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The web app manifest: how the app presents itself once it is installed on a
 * phone or desktop (name, icon, colors, shortcuts).
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'id' => '/',
            'name' => config('app.name'),
            // Shown under the icon on the home screen.
            'short_name' => config('app.name'),
            'lang' => app()->getLocale(),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => '#ffffff',
            'background_color' => '#f4f5f7',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            // Offered when the icon is long-pressed.
            'shortcuts' => [
                ['name' => __('Add refueling'), 'url' => '/refuelings/create'],
                ['name' => __('Timeline'), 'url' => '/timeline'],
                ['name' => __('My garage'), 'url' => '/vehicles'],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }
}
