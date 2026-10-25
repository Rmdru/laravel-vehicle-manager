<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CostForecast;
use App\Services\PriceHistory;
use App\Support\CurrentVehicle;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the vehicle is expected to cost, what it cost per kilometer, whether
 * the repairs ahead are still worth it, and what was paid where.
 */
class CostForecastController extends Controller
{
    public function __invoke(CostForecast $forecasts, PriceHistory $prices): Response
    {
        $vehicle = CurrentVehicle::get();
        $money = fn (?float $value, int $decimals = 0) => $value === null ? null : '€ '.number_format($value, $decimals, ',', '.');
        $forecast = $forecasts->forecast($vehicle);
        $perKm = $forecasts->perKm($vehicle);
        $verdict = $forecasts->repairOrReplace($vehicle, $forecast);

        return Inertia::render('CostForecast', [
            'forecast' => [
                'groups' => array_map(fn (array $group) => [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'items' => array_map(fn (array $item) => [...$item, 'amount' => $money($item['amount'])], $group['items']),
                ], $forecast['groups']),
                'total' => $money($forecast['total']),
                'perMonth' => $money($forecast['perMonth']),
                'unknown' => $forecast['unknown'],
                'depreciation' => $money($forecast['depreciation']),
            ],
            'perKm' => $perKm === null ? null : [
                'since' => Carbon::parse($perKm['since'])->isoFormat('D MMMM YYYY'),
                'km' => number_format($perKm['km'], 0, ',', '.').' km',
                'costs' => $money($perKm['costs']),
                'perKm' => $money($perKm['perKm'], 2),
                'depreciation' => $money($perKm['depreciation']),
                'perKmWithDepreciation' => $money($perKm['perKmWithDepreciation'], 2),
                'years' => array_map(fn (array $year) => [
                    'year' => $year['year'],
                    'km' => number_format($year['km'], 0, ',', '.').' km',
                    'costs' => $money($year['costs']),
                    'perKm' => $money($year['perKm'], 2),
                ], $perKm['years']),
            ],
            'verdict' => $verdict === null ? null : [
                'verdict' => $verdict['verdict'],
                'text' => match ($verdict['verdict']) {
                    'replace' => __('The maintenance and repairs ahead cost :ahead, :share% of what the vehicle is worth (:value). Compare repairing with replacing it before you have it done.', $this->replacements($verdict, $money)),
                    'weigh' => __('The maintenance and repairs ahead cost :ahead, :share% of what the vehicle is worth (:value). Worth it still, but ask for a second quote for the big jobs.', $this->replacements($verdict, $money)),
                    default => __('The maintenance and repairs ahead cost :ahead, :share% of what the vehicle is worth (:value): repairing is the cheaper choice.', $this->replacements($verdict, $money)),
                },
            ],
            'comparisonUrl' => route('dashboard'),
            'prices' => $prices->byComponent($vehicle)
                ->map(fn ($history, string $key) => [
                    'key' => $key,
                    'label' => __(config("maintenance_plan.components.{$key}.label", $key)),
                    'icon' => config("maintenance_plan.components.{$key}.icon", 'mdi:car-wrench'),
                    'prices' => $history->take(5)->map(fn (array $price) => [
                        'date' => Carbon::parse($price['date'])->isoFormat('MMM YYYY'),
                        'garage' => $price['garage'],
                        'price' => $money($price['price'], 2),
                        'url' => route('maintenances.show', $price['maintenance_id']),
                    ])->values()->all(),
                ])
                ->sortBy('label')
                ->values()
                ->all(),
            'garages' => array_map(fn (array $garage) => [
                ...$garage,
                'total' => $money($garage['total']),
                'average' => $money($garage['average']),
                'last' => Carbon::parse($garage['last'])->isoFormat('MMM YYYY'),
            ], $prices->byGarage($vehicle)),
        ]);
    }

    /**
     * @param  array{value: float, ahead: float, share: float}  $verdict
     * @return array<string, string>
     */
    private function replacements(array $verdict, callable $money): array
    {
        return [
            'ahead' => $money($verdict['ahead']),
            'share' => (string) (int) round($verdict['share'] * 100),
            'value' => $money($verdict['value']),
        ];
    }
}
