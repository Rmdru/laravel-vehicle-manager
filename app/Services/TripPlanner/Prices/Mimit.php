<?php

declare(strict_types=1);

namespace App\Services\TripPlanner\Prices;

use App\Models\StationPrice;
use Illuminate\Support\Facades\Http;

/**
 * Italy: every morning the ministry (MIMIT) publishes the prices of all gas
 * stations, self service and served apart, and in a file of their own where
 * the stations are. Both are CSV files with a line before the header; the
 * separator was a semicolon and is a pipe now.
 */
class Mimit implements BulkStationPrices
{
    /** The fuels of the app, by the description of the ministry: premium kinds are left out. */
    private const FUELS = [
        'benzina' => ['e5', 'Unleaded 95 (E5)'],
        'gasolio' => ['diesel', 'Diesel'],
        'gpl' => ['lpg', 'LPG'],
        'metano' => ['cng', 'CNG'],
    ];

    public function country(): string
    {
        return 'IT';
    }

    public function all(): iterable
    {
        $stations = [];

        foreach ($this->rows(config('trip_planner.prices.italy.stations_url')) as $row) {
            $lat = $this->number($row['latitudine'] ?? null);
            $lon = $this->number($row['longitudine'] ?? null);

            if ($lat && $lon && isset($row['idimpianto'])) {
                $stations[$row['idimpianto']] = [
                    'id' => (string) $row['idimpianto'],
                    'name' => trim(($row['bandiera'] ?? '').' '.($row['comune'] ?? '')) ?: null,
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'prices' => [],
                ];
            }
        }

        foreach ($this->rows(config('trip_planner.prices.italy.prices_url')) as $row) {
            $fuel = self::FUELS[strtolower(trim((string) ($row['desccarburante'] ?? '')))] ?? null;
            $price = $this->number($row['prezzo'] ?? null);
            $id = $row['idimpianto'] ?? null;

            if ($fuel && $price && isset($stations[$id])) {
                $stations[$id]['prices'][] = [
                    'fuel' => $fuel[0],
                    'fuel_type' => $fuel[1],
                    'price' => $price,
                    'service' => ($row['isself'] ?? '') === '1' ? StationPrice::SELF : StationPrice::SERVED,
                    'updated_at' => $this->date($row['dtcomu'] ?? null),
                ];
            }
        }

        return array_values(array_filter($stations, fn (array $station) => $station['prices'] !== []));
    }

    /**
     * The rows of a file, by the names of the header in lower case.
     *
     * @return list<array<string, string>>
     */
    private function rows(string $url): array
    {
        $lines = preg_split('/\r\n|\n|\r/', Http::timeout(60)->withUserAgent('Mozilla/5.0')->get($url)->throw()->body()) ?: [];
        // The first line tells when the file was made; the header comes after.
        $start = isset($lines[0]) && stripos($lines[0], 'idimpianto') === false ? 1 : 0;
        $header = $lines[$start] ?? '';
        $separator = str_contains($header, '|') ? '|' : ';';
        $names = array_map(fn (string $name) => strtolower(trim($name)), explode($separator, $header));
        $rows = [];

        foreach (array_slice($lines, $start + 1) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = explode($separator, $line);

            if (count($values) >= count($names)) {
                $rows[] = array_combine($names, array_slice($values, 0, count($names)));
            }
        }

        return $rows;
    }

    private function number(?string $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) && (float) $value != 0.0 ? (float) $value : null;
    }

    /**
     * Such as 07/11/2026 08:12:45.
     */
    private function date(?string $value): ?string
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4}) (\d{2}:\d{2}:\d{2})$#', trim((string) $value), $match)
            ? "{$match[3]}-{$match[2]}-{$match[1]} {$match[4]}"
            : null;
    }
}
