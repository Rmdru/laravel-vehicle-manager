<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Iconify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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
            $set = Iconify::set($prefix, $names);

            if ($set === null) {
                $this->error($prefix.': Iconify request failed');
                $failed = true;

                continue;
            }

            foreach ($names as $name) {
                $svg = Iconify::svg($set, $name);

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
}
