<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Refueling;
use App\Models\Vehicle;
use App\Support\StatusNotification;

/**
 * When the tank is nearly empty by the estimate of the fuel advice: where
 * near home it is cheapest to refuel, as a notification under "Needs
 * attention" and a push. Once per tank: the notification is of the latest
 * refueling, and the price in its text changing does not make it new.
 */
class LowTank
{
    public function __construct(
        private readonly FuelAdviceFacts $facts,
        private readonly FuelComparison $comparison,
    ) {
        //
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        $percentage = $this->facts->nextRefueling($vehicle)['estimated_tank_percentage_now'] ?? null;

        if ($percentage === null || $percentage > (int) config('fuel_advice.low_tank_percentage')) {
            return [];
        }

        $near = $this->comparison->nearHome($vehicle);
        $cheapest = $near['stations'][0] ?? null;
        $last = Refueling::query()->where('vehicle_id', $vehicle->id)->newest()->value('id');

        if ($cheapest === null || $last === null) {
            return [];
        }

        $price = '€ '.number_format((float) $cheapest['price'], 3, ',', '.');
        $station = $cheapest['name'] ?? __('Gas station');
        // A few percent is the estimate saying it is about empty: not "0% full".
        $text = $percentage <= 5
            ? __('Your tank is nearly empty. Cheapest near you: :station :price', ['station' => $station, 'price' => $price])
            : __('Your tank is about :percentage% full. Cheapest near you: :station :price', ['percentage' => $percentage, 'station' => $station, 'price' => $price]);

        // Compared with the gas station the vehicle usually refuels at, when it is dearer.
        if (($cheapest['difference'] ?? 0) < 0 && $near['usual'] !== null) {
            $text .= ' '.__('(:cents ct below :usual)', [
                'cents' => (int) round(-$cheapest['difference'] * 100),
                'usual' => $near['usual']['name'],
            ]);
        }

        $types = StatusNotification::types();

        return [[
            ...$types['info'],
            'key' => 'low_tank:'.$last,
            'type' => 'info',
            'text' => $text,
            // The same notification while it is the same tank, whatever the price does.
            'fingerprint' => 'low_tank:'.$last,
            // Pushed as the owner chose for a nearly empty tank.
            'push' => 'fuel',
            'icon' => 'ic:round-local-gas-station',
            'typeIcon' => $types['info']['icon'],
            'priority' => $types['info']['priority'],
            'hasModal' => false,
            'data' => [],
            'url' => route('stations'),
            'urlLabel' => __('Fuel stations'),
        ]];
    }
}
