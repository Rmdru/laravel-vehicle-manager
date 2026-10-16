<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Iconify
{
    /**
     * The icon set with the given icons in the Iconify JSON format: empty for an
     * unknown set, null when Iconify could not be reached.
     *
     * @param  list<string>  $names
     * @return array<string, mixed>|null
     */
    public static function set(string $prefix, array $names, int $timeout = 10, int $tries = 3): ?array
    {
        try {
            // The API limits bursts of requests.
            $response = Http::timeout($timeout)
                ->retry($tries, 2000, throw: false)
                ->get('https://api.iconify.design/'.$prefix.'.json', ['icons' => implode(',', $names)]);
        } catch (\Exception $e) {
            Log::warning('Iconify request failed: '.$e->getMessage());

            return null;
        }

        if (! $response->successful() && $response->status() !== 404) {
            return null;
        }

        // An unknown icon set answers with a bare 404.
        return is_array($response->json()) ? $response->json() : [];
    }

    /**
     * An icon of a set as a standalone SVG, or null when the set does not have it.
     *
     * @param  array<string, mixed>  $set  An icon set in the Iconify JSON format.
     */
    public static function svg(array $set, string $name): ?string
    {
        $alias = $set['aliases'][$name] ?? null;

        // Aliases that flip or rotate their parent are rare; use the parent instead.
        if ($alias !== null && array_diff(array_keys($alias), ['parent']) !== []) {
            return null;
        }

        $icon = $set['icons'][$alias['parent'] ?? $name] ?? null;

        if (! isset($icon['body'])) {
            return null;
        }

        $viewBox = implode(' ', [
            $icon['left'] ?? 0,
            $icon['top'] ?? 0,
            $icon['width'] ?? $set['width'] ?? 16,
            $icon['height'] ?? $set['height'] ?? 16,
        ]);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="'.$viewBox.'">'.$icon['body'].'</svg>';
    }
}
