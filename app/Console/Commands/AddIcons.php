<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class AddIcons extends Command
{
    protected $signature = 'icons:add {icons* : Iconify names, e.g. mdi:car-wrench}';

    protected $description = 'Download icons from Iconify into resources/icons';

    public function handle(): int
    {
        $failed = false;
        $sets = [];

        foreach ($this->argument('icons') as $icon) {
            if (preg_match('/^([a-z0-9-]+):([a-z0-9-]+)$/', $icon, $match)) {
                $sets[$match[1]][] = $match[2];
            } else {
                $this->error($icon.': not an Iconify name (prefix:name)');
                $failed = true;
            }
        }

        foreach ($sets as $prefix => $names) {
            // One request per icon set; the API limits bursts of requests.
            $response = Http::retry(3, 2000, throw: false)
                ->get('https://api.iconify.design/'.$prefix.'.json', ['icons' => implode(',', $names)]);

            if (! $response->successful() && $response->status() !== 404) {
                $this->error($prefix.': Iconify request failed ('.$response->status().')');
                $failed = true;

                continue;
            }

            // An unknown icon set answers with a bare 404.
            $set = is_array($response->json()) ? $response->json() : [];

            foreach ($names as $name) {
                $svg = $this->svg($set, $name);

                if ($svg === null) {
                    $this->error($prefix.':'.$name.': not found on Iconify');
                    $failed = true;

                    continue;
                }

                File::ensureDirectoryExists(resource_path('icons/'.$prefix));
                File::put(resource_path('icons/'.$prefix.'/'.$name.'.svg'), $svg."\n");

                $this->info($prefix.':'.$name.': added');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $set  An icon set in the Iconify JSON format.
     */
    private function svg(array $set, string $name): ?string
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
