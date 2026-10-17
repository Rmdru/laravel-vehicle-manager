<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const LOCALES = [
        'en' => 'English',
        'nl' => 'Nederlands',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'it' => 'Italiano',
        'es' => 'Español',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()->language
            ?? ($request->hasSession() ? $request->session()->get('locale') : null);

        if (is_string($locale) && array_key_exists($locale, self::LOCALES)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
