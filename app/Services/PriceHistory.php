<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * What was paid for the components of the maintenance plan, and where: from
 * the tasks of the maintenances that name a component and have a price.
 */
class PriceHistory
{
    /**
     * Per component the prices paid, the most recent first.
     *
     * @return Collection<array-key, Collection<int, array{date: string, garage: string|null, price: float, km: int|null, maintenance_id: string}>> By component key.
     */
    public function byComponent(Vehicle $vehicle): Collection
    {
        $vehicle->loadMissing('maintenances');

        $prices = [];

        foreach ($vehicle->maintenances->sortByDesc('date') as $maintenance) {
            foreach ((array) ($maintenance->tasks ?? []) as $task) {
                if (! is_array($task) || (float) ($task['price'] ?? 0) <= 0) {
                    continue;
                }

                $keys = MaintenancePlanService::componentsOf($task);

                foreach ($keys as $key) {
                    $prices[$key][] = [
                        'date' => $maintenance->date->toDateString(),
                        'garage' => filled($maintenance->garage) ? (string) $maintenance->garage : null,
                        // A task for more components at once: the price is theirs together.
                        'price' => round((float) $task['price'] / count($keys), 2),
                        'km' => $maintenance->mileage ? (int) $maintenance->mileage : null,
                        'maintenance_id' => (string) $maintenance->id,
                    ];
                }
            }
        }

        return collect($prices)->map(fn (array $rows) => collect($rows));
    }

    /**
     * What a component usually costs, going by what was paid for it: the
     * latest price, as prices go up. Null when it was never paid for.
     */
    public function estimate(Vehicle $vehicle, string $key): ?float
    {
        return $this->byComponent($vehicle)->get($key)?->first()['price'] ?? null;
    }

    /**
     * Per garage the visits and what was paid, the most recent first.
     *
     * @return list<array{garage: string, visits: int, total: float, average: float, last: string}>
     */
    public function byGarage(Vehicle $vehicle): array
    {
        $vehicle->loadMissing('maintenances');

        return $vehicle->maintenances
            ->filter(fn (Maintenance $maintenance) => filled($maintenance->garage) && (float) $maintenance->total_price > 0)
            ->groupBy(fn (Maintenance $maintenance) => mb_strtolower(trim((string) $maintenance->garage)))
            ->map(fn (Collection $visits) => [
                'garage' => trim((string) $visits->sortByDesc('date')->first()->garage),
                'visits' => $visits->count(),
                'total' => round((float) $visits->sum('total_price'), 2),
                'average' => round((float) $visits->avg('total_price'), 2),
                'last' => $visits->max('date')->toDateString(),
            ])
            ->sortByDesc('last')
            ->values()
            ->all();
    }
}
