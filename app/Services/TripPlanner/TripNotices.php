<?php

declare(strict_types=1);

namespace App\Services\TripPlanner;

use App\Models\Trip;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Locale;

/**
 * What the driver should know about the vehicle before a trip, from what the
 * app knows of it: papers that expire on the way, maintenance that is due,
 * vignettes and environmental stickers the route calls for, winter tires.
 */
class TripNotices
{
    /** The notices, by key: translated when they are shown. */
    public const MESSAGES = [
        'apk_expired' => 'The MOT (APK) expired on :date.',
        'apk_expires' => 'The MOT (APK) expires on :date, before the end of this trip.',
        'maintenance_date' => 'Maintenance is due on :date, before the end of this trip.',
        'maintenance_distance' => 'Maintenance is due in :distance km, and this trip is :trip km.',
        'uninsured' => 'According to the RDW the vehicle is not insured.',
        'insurance_ends' => 'The insurance ends on :date, before the end of this trip.',
        'tax_ends' => 'The road tax is paid until :date, before the end of this trip.',
        'vignette' => 'A vignette is needed in :country: none is registered for the dates of this trip.',
        'sticker' => 'Cities in :country have environmental zones: no environmental sticker is registered for :country.',
        'winter_tires' => 'Winter tires can be required in :countries at this time of year.',
    ];

    /** Countries where cities ask for an environmental sticker of their own. */
    private const STICKER_COUNTRIES = ['DE', 'FR', 'AT'];

    /** Countries where winter tires are required in winter, or in wintry weather. */
    private const WINTER_TIRE_COUNTRIES = ['AT', 'DE', 'CZ', 'SK', 'SI', 'HR', 'IT', 'SE', 'NO', 'FI', 'EE', 'LV', 'LT', 'RO', 'BG'];

    /**
     * @param  list<string>  $countries  The countries the route passes, ISO 2 codes.
     * @param  list<string>  $vignettes  The countries the route needs a vignette in, ISO 2 codes.
     * @param  list<string>  $destinations  The countries of the destinations, along the way and at the end, ISO 2 codes.
     * @return list<array{level: string, key: string, params: array<string, string|int>}>
     */
    public function for(Trip $trip, array $countries, array $vignettes, array $destinations, float $distance, CarbonImmutable $departure, CarbonImmutable $arrival): array
    {
        $vehicle = $trip->vehicle;

        if ($vehicle === null) {
            return [];
        }

        $notices = [];
        $add = function (string $level, string $key, array $params = []) use (&$notices) {
            $notices[] = ['level' => $level, 'key' => $key, 'params' => $params];
        };
        $date = fn (CarbonInterface $date) => $date->toDateString();

        $apk = $vehicle->apkExpiryDate();

        if ($apk && $apk->isPast()) {
            $add('warning', 'apk_expired', ['date' => $date($apk)]);
        } elseif ($apk && $apk->lte($arrival)) {
            $add('warning', 'apk_expires', ['date' => $date($apk)]);
        }

        $maintenance = $vehicle->nextMaintenance();

        if ($maintenance && $maintenance['date']->lte($arrival)) {
            $add('warning', 'maintenance_date', ['date' => $date($maintenance['date'])]);
        } elseif ($maintenance && $maintenance['distance'] !== null && $maintenance['distance'] < $distance / 1000) {
            $add('warning', 'maintenance_distance', ['distance' => max(0, (int) round($maintenance['distance'])), 'trip' => (int) round($distance / 1000)]);
        }

        $this->papers($vehicle, $arrival, $add);

        foreach ($vignettes as $country) {
            if (! $this->holds($vehicle->vignettes, $country, $departure, $arrival)) {
                $add('warning', 'vignette', ['country' => $country]);
            }
        }

        foreach (array_unique(array_intersect($destinations, self::STICKER_COUNTRIES)) as $country) {
            if (! $this->holds($vehicle->environmentalStickers, $country, $departure, $arrival)) {
                $add('info', 'sticker', ['country' => $country]);
            }
        }

        $winter = array_values(array_intersect(self::WINTER_TIRE_COUNTRIES, $countries));

        if ($winter && $this->inWinter($departure, $arrival) && ! $this->onWinterTires($vehicle)) {
            $add('info', 'winter_tires', ['countries' => implode(', ', $winter)]);
        }

        return $notices;
    }

    /**
     * The notices in the language of the user: countries by their name.
     *
     * @param  list<array{level: string, key: string, params: array<string, string|int>}>  $notices
     * @return list<array{level: string, message: string}>
     */
    public static function translate(array $notices): array
    {
        $country = fn (string $code) => extension_loaded('intl')
            ? (Locale::getDisplayRegion('-'.$code, app()->getLocale()) ?: $code)
            : $code;
        $date = fn (string $value) => CarbonImmutable::parse($value)->locale(app()->getLocale())->isoFormat('D MMMM YYYY');

        return array_map(function (array $notice) use ($country, $date) {
            $params = $notice['params'];

            if (isset($params['date'])) {
                $params['date'] = $date((string) $params['date']);
            }

            if (isset($params['country'])) {
                $params['country'] = $country((string) $params['country']);
            }

            if (isset($params['countries'])) {
                $params['countries'] = implode(', ', array_map($country, explode(', ', (string) $params['countries'])));
            }

            return ['level' => $notice['level'], 'message' => __(self::MESSAGES[$notice['key']] ?? $notice['key'], $params)];
        }, $notices);
    }

    /**
     * The insurance and the road tax.
     */
    private function papers(Vehicle $vehicle, CarbonImmutable $arrival, callable $add): void
    {
        $insured = $vehicle->rdw_data['wam_verzekerd'] ?? null;

        if ($insured !== null && $insured !== 'Ja') {
            $add('warning', 'uninsured');
        } else {
            $insurance = $vehicle->insurances->where('start_date', '<=', today())->sortByDesc('start_date')->first();

            if ($insurance?->end_date && $insurance->end_date->lt($arrival)) {
                $add('warning', 'insurance_ends', ['date' => $insurance->end_date->toDateString()]);
            }
        }

        $tax = $vehicle->taxes->where('start_date', '<=', today())->sortByDesc('start_date')->first();

        if ($tax?->end_date && $tax->end_date->lt($arrival)) {
            $add('warning', 'tax_ends', ['date' => $tax->end_date->toDateString()]);
        }
    }

    /**
     * Whether a vignette or a sticker of a country holds for the whole trip.
     *
     * @param  iterable<object>  $records
     */
    public function holds(iterable $records, string $country, CarbonImmutable $departure, CarbonImmutable $arrival): bool
    {
        $key = collect(config('countries'))->search(fn (array $config) => $config['iso_code'] === $country);

        foreach ($records as $record) {
            if ($record->country === $key
                && ($record->start_date === null || $record->start_date->lte($departure))
                && ($record->end_date === null || $record->end_date->endOfDay()->gte($arrival))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a trip falls in the winter period, from November to mid April.
     */
    private function inWinter(CarbonImmutable $departure, CarbonImmutable $arrival): bool
    {
        foreach ([$departure, $arrival] as $moment) {
            if ($moment->month >= 11 || $moment->month <= 3 || ($moment->month === 4 && $moment->day <= 15)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the vehicle drove on winter or all season tires at its latest refueling.
     */
    private function onWinterTires(Vehicle $vehicle): bool
    {
        $tires = $vehicle->refuelings()->newest()->value('tires');
        $tires = $tires instanceof \BackedEnum ? $tires->value : $tires;

        return in_array($tires, ['winter', 'all_season'], true);
    }
}
