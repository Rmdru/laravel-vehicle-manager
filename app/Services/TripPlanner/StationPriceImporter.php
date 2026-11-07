<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\StationPrice;
use App\Services\TripPlanner\Prices\BulkStationPrices;
use App\Services\TripPlanner\Prices\Mimit;
use App\Services\TripPlanner\Prices\Minetur;
use App\Services\TripPlanner\Prices\PrixCarburants;
use App\Services\TripPlanner\Prices\UkRetailers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Imports the prices of the countries that publish all gas stations at once,
 * so that the station map can show them without asking: a source that fails
 * keeps the prices it had.
 */
class StationPriceImporter
{
    /**
     * @return list<BulkStationPrices>
     */
    public function sources(): array
    {
        return [
            new PrixCarburants,
            new Minetur,
            new UkRetailers,
            // Not always reachable from abroad.
            ...(config('trip_planner.prices.italy.enabled') ? [new Mimit] : []),
        ];
    }

    /**
     * @param  list<string>  $countries  Only these, ISO 2 codes; none for all.
     * @return array<string, int|null> The prices imported by country; null when the source failed.
     */
    public function import(array $countries = []): array
    {
        $result = [];

        foreach ($this->sources() as $source) {
            if ($countries !== [] && ! in_array($source->country(), $countries, true)) {
                continue;
            }

            try {
                $result[$source->country()] = $this->importFrom($source);
            } catch (Throwable $exception) {
                $result[$source->country()] = null;

                Log::warning('Station prices could not be imported.', ['country' => $source->country(), 'message' => $exception->getMessage()]);
            }
        }

        return $result;
    }

    private function importFrom(BulkStationPrices $source): int
    {
        $name = class_basename($source);
        $now = now();
        $rows = [];

        foreach ($source->all() as $station) {
            foreach ($station['prices'] as $price) {
                $rows[] = [
                    'country' => $source->country(),
                    'source' => $name,
                    'source_id' => mb_substr($station['id'], 0, 64),
                    'name' => $station['name'] !== null ? mb_substr($station['name'], 0, 255) : null,
                    'latitude' => round($station['latitude'], 6),
                    'longitude' => round($station['longitude'], 6),
                    'fuel' => $price['fuel'],
                    'fuel_type' => $price['fuel_type'],
                    'price' => round($price['price'], 3),
                    'service' => $price['service'],
                    'reported_at' => $this->date($price['updated_at']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Nothing at all: the source is down, rather than every station closed.
        if ($rows === []) {
            throw new RuntimeException('The source gave no prices.');
        }

        DB::transaction(function () use ($name, $rows) {
            StationPrice::query()->where('source', $name)->delete();

            foreach (array_chunk($rows, 1000) as $chunk) {
                StationPrice::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : rescue(fn () => Carbon::parse($value)->toDateTimeString(), null, false);
    }
}
