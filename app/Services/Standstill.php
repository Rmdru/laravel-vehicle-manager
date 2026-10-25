<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Support\StatusNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * What a vehicle that stands still needs: one that is suspended ("geschorst")
 * is not driven, and a vehicle that is not driven ages in its own way: the
 * 12 volt battery runs flat, the tires get flat spots, the brakes rust and
 * the fuel ages.
 */
class Standstill
{
    /** Days standing still after which the tires and the brakes need attention. */
    private const LONG = 30;

    /**
     * The day the vehicle was driven last, going by its records; null when
     * they do not tell.
     */
    public function since(Vehicle $vehicle): ?CarbonImmutable
    {
        $vehicle->loadMissing(['refuelings', 'maintenances']);

        $last = collect([
            $vehicle->refuelings->max('date'),
            $vehicle->maintenances->filter(fn ($maintenance) => (int) $maintenance->mileage > 0)->max('date'),
        ])->filter()->max();

        return $last ? CarbonImmutable::parse($last)->startOfDay() : null;
    }

    /**
     * The notifications of a suspended vehicle; none for one that is not.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(Vehicle $vehicle): array
    {
        if ($vehicle->status !== VehicleStatus::Suspended->value) {
            return [];
        }

        $types = StatusNotification::types();
        $notification = fn (string $key, string $type, string $text, string $icon, array $action = []) => [
            ...$types[$type],
            ...$action,
            'key' => 'standstill:'.$key,
            'type' => $type,
            'text' => $text,
            'icon' => $icon,
            'typeIcon' => $types[$type]['icon'],
            'hasModal' => false,
            'data' => [],
        ];
        $since = $this->since($vehicle);
        $days = $since ? (int) $since->diffInDays(today()) : null;
        $electric = in_array($vehicle->powertrain, ['electricity', 'hydrogen'], true) || str_ends_with((string) $vehicle->powertrain, 'plugin_hybrid');
        $notifications = [];

        $notifications[] = $notification('battery', 'info', __('Suspended: charge the 12 volt battery every few weeks, or keep it on a trickle charger, so that it does not run flat.'), 'mdi:car-battery');

        if ($electric) {
            $notifications[] = $notification('high_voltage', 'info', __('Leave the high voltage battery between 50% and 80% charged while the vehicle stands still, and check it once a month.'), 'mdi:battery-charging');
        }

        if ($days !== null && $days >= self::LONG) {
            $notifications[] = $notification('tires_brakes', 'info', __('Standing still since :date: pump the tires up a little against flat spots, and expect rust on the brake discs that the first drives brake off.', ['date' => $since->isoFormat('D MMMM YYYY')]), 'mdi:car-off');
        }

        $mot = $vehicle->rdw_data['vervaldatum_apk_dt'] ?? null;

        if ($mot && Carbon::parse($mot)->isPast()) {
            $notifications[] = $notification('mot', 'warning', __('The MOT expired while suspended: you need a valid MOT to lift the suspension. Ask the RDW on what terms you may drive to a booked inspection.'), 'ic:baseline-security');
        }

        if ($vehicle->insurances()->where('start_date', '<=', today())->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', today()))->exists()) {
            $notifications[] = $notification('insurance', 'info', __('While the vehicle is suspended, third party insurance is not required: ask the insurer about a lower premium or a storage insurance.'), 'mdi:shield-car', [
                'url' => route('insurances.index'),
                'urlLabel' => __('Insurances'),
            ]);
        }

        return $notifications;
    }
}
