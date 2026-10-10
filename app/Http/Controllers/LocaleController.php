<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(SetLocale::LOCALES))],
        ]);

        $request->session()->put('locale', $data['locale']);
        $request->user()?->forceFill(['language' => $data['locale']])->save();

        return back();
    }
}
