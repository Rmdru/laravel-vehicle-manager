<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FuelAdvice;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Support\StatusNotification;
use Locale;

/**
 * When the tank is nearly empty by the estimate of the fuel advice: where
 * to refuel, as a notification under "Needs attention" and a push. Once per
 * tank: the notification is of the latest refueling, and the price in its
 * text changing does not make it new.
 *
 * It follows the fuel advice of today, so that the two never contradict each
 * other: abroad when the advice says so (with the cheapest near home for when
 * the driver does not go that way), not yet when the advice names a later
 * day. With it, the notification of the advice itself is left out (see
 * DashboardService): one notification about refueling, not two.
 */
class LowTank
{
    /** At most this full the tank is about empty: kept as a margin when reckoning how far it still goes. */
    private const NEARLY_EMPTY = 5;

    public function __construct(
        private readonly FuelAdviceFacts $facts,
        private readonly FuelComparison $comparison,
    ) {
        //
    }

    /**
     * @param  FuelAdvice|null  $advice  The fuel advice of today, if there is one.
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle, ?FuelAdvice $advice = null): array
    {
        $next = $this->facts->nextRefueling($vehicle);
        $percentage = $next['estimated_tank_percentage_now'] ?? null;

        if ($percentage === null || $percentage > (int) config('fuel_advice.low_tank_percentage')) {
            return [];
        }

        $nearlyEmpty = $percentage <= self::NEARLY_EMPTY;
        // How far the tank still goes, keeping the last few percent as a margin; null when unknown.
        $range = $vehicle->tank_capacity && ($next['average_consumption_l_per_100km'] ?? 0) > 0
            ? max(0, (int) $vehicle->tank_capacity * ($percentage - self::NEARLY_EMPTY) / 100) / $next['average_consumption_l_per_100km'] * 100
            : null;

        // The advice names a later day (such as prices going down): not relevant yet, as long as the tank lasts till then.
        if ($advice !== null && $advice->action !== 'anytime' && $advice->recommended_date?->gt(today()->addDay())
            && $range !== null && $range >= today()->diffInDays($advice->recommended_date) * ($next['average_km_per_day'] ?? INF)) {
            return [];
        }

        $near = $this->comparison->nearHome($vehicle);
        $cheapest = $near['stations'][0] ?? null;
        $last = Refueling::query()->where('vehicle_id', $vehicle->id)->newest()->value('id');
        $abroad = $this->abroad($advice, $range);

        if (($cheapest === null && $abroad === null) || $last === null) {
            return [];
        }

        // A few percent is the estimate saying it is about empty: not "0% full".
        $text = $nearlyEmpty
            ? __('Your tank is nearly empty.')
            : __('Your tank is about :percentage% full.', ['percentage' => $percentage]);

        if ($abroad !== null) {
            $text .= ' '.$abroad;
        } elseif ($advice?->action === 'wait' && $advice->recommended_date?->isTomorrow() && $range !== null && $range >= ($next['average_km_per_day'] ?? INF)) {
            $text .= ' '.__('Prices are expected to go down: refuel tomorrow if you can.');
        }

        if ($cheapest !== null) {
            $text .= ' '.__($abroad === null ? 'Cheapest near you: :station :price' : 'Not going that way? Near you: :station :price', [
                'station' => $cheapest['name'] ?? __('Gas station'),
                'price' => $this->money((float) $cheapest['price']),
            ]);

            // Compared with the gas station the vehicle usually refuels at, when it is dearer.
            if ($abroad === null && ($cheapest['difference'] ?? 0) < 0 && $near['usual'] !== null) {
                $text .= ' '.__('(:cents ct below :usual)', [
                    'cents' => (int) round(-$cheapest['difference'] * 100),
                    'usual' => $near['usual']['name'],
                ]);
            }
        }

        $types = StatusNotification::types();

        return [[
            ...$types['info'],
            'key' => 'low_tank:'.$last,
            'type' => 'info',
            'text' => $text,
            // The same notification while it is the same tank, whatever the price or the advice does.
            'fingerprint' => 'low_tank:'.$last,
            // Pushed as the owner chose for a nearly empty tank.
            'push' => 'fuel',
            'icon' => $abroad === null ? 'ic:round-local-gas-station' : 'mdi:earth-arrow-right',
            'typeIcon' => $types['info']['icon'],
            // On top, where the notification of the advice it replaces would be.
            'priority' => $advice === null ? $types['info']['priority'] : -1,
            'hasModal' => false,
            'data' => [],
            // The advice it follows, behind "More information".
            'opensFuelAdvice' => $advice !== null,
            'url' => route('stations'),
            'urlLabel' => __('Fuel stations'),
        ]];
    }

    /**
     * Where to refuel abroad, when the advice of today says so: the cheapest
     * gas station near the crossing of the border, or else the average price
     * of the country. Null when the advice is about the home country, or when
     * the tank does not surely reach it.
     */
    private function abroad(?FuelAdvice $advice, ?float $range): ?string
    {
        if ($advice?->action !== 'refuel_abroad' || ! $advice->country || $range === null) {
            return null;
        }

        $country = collect((array) ($advice->facts['countries'] ?? []))->firstWhere('country', $advice->country);
        $station = $country['cheapest_gas_stations'][0] ?? null;
        // To the crossing of the border, and from there to the gas station.
        $distance = is_numeric($country['distance_from_driver_km'] ?? null)
            ? (float) $country['distance_from_driver_km'] + (float) ($station['distance_km'] ?? 0)
            : null;

        if ($country === null || $distance === null || $range < $distance) {
            return null;
        }

        // In the language of the user ("België"), not as the configuration has it ("België/Belgique (Belgium)").
        $iso = config("countries.{$advice->country}.iso_code");
        $name = is_string($iso) && extension_loaded('intl')
            ? (Locale::getDisplayRegion('-'.$iso, app()->getLocale()) ?: $iso)
            : (config("countries.{$advice->country}.name") ?? $advice->country);

        return $station === null
            ? __('Refuel in :country: on average :price.', ['country' => $name, 'price' => $this->money((float) $country['latest_price'])])
            : __('Refuel in :country: :station :price (:distance km past the border).', [
                'country' => $name,
                'station' => $station['name'],
                'price' => $this->money((float) $station['price']),
                'distance' => max(1, (int) round((float) $station['distance_km'])),
            ]);
    }

    private function money(float $value): string
    {
        return '€ '.number_format($value, 3, ',', '.');
    }
}
