<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STYLES = ['r' => 'round', 'o' => 'outline', 's' => 'sharp', 'tt' => 'twotone'];

    // Icons that are named differently on Iconify than the generic rules give.
    private const EXCEPTIONS = [
        'fas-birthday-cake' => 'fa7-solid:cake-candles',
        'fas-car-crash' => 'fa7-solid:car-burst',
        'fas-parking' => 'fa7-solid:square-parking',
        'fas-smile' => 'fa7-solid:face-smile',
        'gmdi-sunny-snowing' => 'material-symbols:sunny-snowing',
        'govicon-construction' => 'custom:construction',
    ];

    public function up(): void
    {
        Schema::table('fines', function (Blueprint $table) {
            $table->string('icon')->nullable()->change();
        });

        $this->rename($this->toIconify(...));
    }

    public function down(): void
    {
        $this->rename($this->toBlade(...));
    }

    /**
     * @param  Closure(string): string  $convert
     */
    private function rename(Closure $convert): void
    {
        foreach (DB::table('fines')->whereNotNull('icon')->distinct()->pluck('icon') as $icon) {
            DB::table('fines')->where('icon', $icon)->update(['icon' => $convert($icon)]);
        }

        foreach (['maintenances' => 'tasks', 'vehicles' => 'specifications'] as $table => $column) {
            foreach (DB::table($table)->whereNotNull($column)->get(['id', $column]) as $row) {
                $items = json_decode($row->{$column}, true);

                if (! is_array($items)) {
                    continue;
                }

                $renamed = array_map(fn ($item) => is_array($item) && filled($item['icon'] ?? null)
                    ? [...$item, 'icon' => $convert($item['icon'])]
                    : $item, $items);

                if ($renamed !== $items) {
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode($renamed)]);
                }
            }
        }
    }

    /**
     * A Blade Icons name ("gmdi-route-r") as its Iconify name ("ic:round-route").
     */
    private function toIconify(string $icon): string
    {
        if (isset(self::EXCEPTIONS[$icon])) {
            return self::EXCEPTIONS[$icon];
        }

        if (! preg_match('/^(gmdi|mdi|fas|maki|tabler|uni)-(.+)$/', $icon, $match)) {
            return $icon;
        }

        [, $prefix, $name] = $match;

        return match ($prefix) {
            'gmdi' => preg_match('/^(.+)-(r|o|s|tt)$/', $name, $style)
                ? 'ic:'.self::STYLES[$style[2]].'-'.$style[1]
                : 'ic:baseline-'.$name,
            'fas' => 'fa7-solid:'.$name,
            'uni' => 'uil:'.preg_replace('/-o$/', '', $name),
            default => $prefix.':'.$name,
        };
    }

    private function toBlade(string $icon): string
    {
        if ($blade = array_search($icon, self::EXCEPTIONS, true)) {
            return $blade;
        }

        if (! preg_match('/^(ic|mdi|fa7-solid|maki|tabler|uil):(.+)$/', $icon, $match)) {
            return $icon;
        }

        [, $prefix, $name] = $match;

        return match ($prefix) {
            'ic' => preg_match('/^(round|outline|sharp|twotone|baseline)-(.+)$/', $name, $style)
                ? 'gmdi-'.$style[2].rtrim('-'.array_search($style[1], self::STYLES, true), '-')
                : $icon,
            'fa7-solid' => 'fas-'.$name,
            'uil' => 'uni-'.$name.'-o',
            default => $prefix.'-'.$name,
        };
    }
};
