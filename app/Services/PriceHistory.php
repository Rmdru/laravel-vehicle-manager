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
     * @return Collection<string, Collection<int, array{date: string, garage: string|null, price: float, km: int|null, maintenance_id: string}>>
     */
    public function byComponent(Vehicle $vehicle): Collection
    {
        $vehicle->loadMissing('maintenances');

        return $vehicle->maintenances
            ->sortByDesc('date')
            ->flatMap(fn (Maintenance $maintenance) => collect($maintenance->tasks ?? [])
                ->filter(fn ($task) => is_array($task) && (float) ($task['price'] ?? 0) > 0)
                ->flatMap(fn (array $task) => collect(MaintenancePlanService::componentsOf($task))
                    // A task for more components at once: the price is theirs together.
                    ->map(fn (string $key) => [
                        'key' => $key,
                        'date' => $maintenance->date->toDateString(),
                        'garage' => $maintenance->garage ?: null,
                        'price' => round((float) $task['price'] / max(1, count(MaintenancePlanService::componentsOf($task))), 2),
                        'km' => $maintenance->mileage ? (int) $maintenance->mileage : null,
                        'maintenance_id' => $maintenance->id,
                    ])))
            ->groupBy('key')
            ->map(fn (Collection $prices) => $prices->map(fn (array $price) => collect($price)->except('key')->all())->values());
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
