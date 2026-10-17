<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RdwService
{
    public function fetchVehicleDataByLicensePlate(string $licensePlate): string
    {
        return $this->call('m9d7-ebf2.json', ['kenteken' => $licensePlate]);
    }

    public function fetchFuelDataByLicensePlate(string $licensePlate): string
    {
        return $this->call('8ys7-d773.json', ['kenteken' => $licensePlate]);
    }

    public function fetchOpenRecallsByLicensePlate(string $licensePlate): string
    {
        return $this->call('t49b-isb7.json', [
            'kenteken' => $licensePlate,
            'code_status' => 'O',
        ]);
    }

    public function fetchRecallByReferenceCode(string $referenceCodeRdw): string
    {
        return $this->call('j9yg-7rg9.json', ['referentiecode_rdw' => $referenceCodeRdw]);
    }

    /**
     * The defects inspections (MOT and others) found, newest first.
     */
    public function fetchInspectionDefectsByLicensePlate(string $licensePlate): string
    {
        return $this->call('a34c-vvps.json', [
            'kenteken' => $licensePlate,
            '$order' => 'meld_datum_door_keuringsinstantie DESC',
            '$limit' => 200,
        ]);
    }

    /**
     * @param  list<string>  $ids
     */
    public function fetchDefectDescriptions(array $ids): string
    {
        $ids = collect($ids)->map(fn (string $id) => "'".preg_replace('/[^A-Za-z0-9]/', '', $id)."'")->implode(',');

        return $this->call('hx2c-gt7k.json', ['$where' => 'gebrek_identificatie in('.$ids.')']);
    }

    /**
     * The defects found at the latest inspection that found any, or null when
     * none were ever found or the RDW could not be reached.
     *
     * @return array{date: string, type: string, defects: list<array{code: string, article: string, description: string, count: int}>}|null
     */
    public function getLatestInspectionDefects(string $licensePlate): ?array
    {
        $rows = collect(json_decode($this->fetchInspectionDefectsByLicensePlate($licensePlate), true) ?: []);

        if ($rows->isEmpty()) {
            return null;
        }

        $date = $rows->first()['meld_datum_door_keuringsinstantie'];
        $latest = $rows->where('meld_datum_door_keuringsinstantie', $date)->values();
        $descriptions = collect(json_decode($this->fetchDefectDescriptions($latest->pluck('gebrek_identificatie')->all()), true) ?: [])
            // A defect can have been described differently over time: the description of the day of the inspection.
            ->filter(fn (array $row) => ($row['ingangsdatum_gebrek'] ?? '0') <= $date && ($row['einddatum_gebrek'] ?? '99999999') >= $date)
            ->keyBy('gebrek_identificatie');

        return [
            'date' => Carbon::createFromFormat('Ymd', $date)->toDateString(),
            'type' => (string) ($latest->first()['soort_erkenning_omschrijving'] ?? ''),
            'defects' => $latest->map(fn (array $row) => [
                'code' => (string) $row['gebrek_identificatie'],
                'article' => (string) ($descriptions[$row['gebrek_identificatie']]['gebrek_artikel_nummer'] ?? ''),
                'description' => (string) ($descriptions[$row['gebrek_identificatie']]['gebrek_omschrijving'] ?? $row['gebrek_identificatie']),
                'count' => (int) ($row['aantal_gebreken_geconstateerd'] ?? 1),
            ])->all(),
        ];
    }

    /**
     * The powertrain the fuels of a vehicle make: the RDW lists a hybrid by its
     * fuel and electricity, and tells whether it can be charged.
     *
     * @param  list<array<string, string>>  $fuelData
     */
    public function getPowertrainOptionFromRdwFuelData(array $fuelData): string
    {
        $fuels = collect($fuelData)->pluck('brandstof_omschrijving')->map(fn ($fuel) => strtolower((string) $fuel));
        $hybrid = collect($fuelData)->pluck('klasse_hybride_elektrisch_voertuig')->filter()->first();
        $powertrains = trans('powertrains');

        $base = match (true) {
            $fuels->contains('waterstof') => 'hydrogen',
            $fuels->contains('diesel') => 'diesel',
            $fuels->contains('benzine') && $fuels->contains('lpg') => 'gasoline_lpg',
            $fuels->contains('benzine') && $fuels->contains('cng') => 'gasoline_cng',
            $fuels->contains('benzine') => 'gasoline',
            $fuels->contains('elektriciteit') => 'electricity',
            default => '',
        };

        $hybridPowertrain = match ($hybrid) {
            'OVC-HEV' => $base.'_plugin_hybrid',
            'NOVC-HEV' => $base.'_full_hybrid',
            default => null,
        };

        return $hybridPowertrain !== null && isset($powertrains[$hybridPowertrain]) ? $hybridPowertrain : $base;
    }

    /**
     * The fuel types a vehicle can take, by its fuels: only a hybrid that can be
     * charged takes electricity.
     *
     * @param  list<array<string, string>>  $fuelData
     * @return list<string>
     */
    public function getFuelTypesFromRdwFuelData(array $fuelData): array
    {
        $chargeable = collect($fuelData)->pluck('klasse_hybride_elektrisch_voertuig')->filter()->first() !== 'NOVC-HEV';

        return collect($fuelData)
            ->flatMap(fn (array $fuel) => match (strtolower((string) ($fuel['brandstof_omschrijving'] ?? ''))) {
                'benzine' => ['Unleaded 95 (E10)', 'Super Plus (E5)'],
                'diesel' => ['Diesel', 'Premium diesel'],
                'lpg' => ['LPG'],
                'cng' => ['CNG'],
                'waterstof' => ['Hydrogen'],
                'elektriciteit' => $chargeable ? ['Electricity AC', 'Electricity DC'] : [],
                default => [],
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The fuel consumption the manufacturer specified, in the unit of the powertrain:
     * by WLTP, or by the older NEDC for vehicles from before WLTP.
     *
     * @param  list<array<string, string>>  $fuelData
     * @return array{value: float, method: string}|null
     */
    public function getFactoryConsumption(array $fuelData, string $powertrain): ?array
    {
        $electric = $powertrain === 'electricity';
        $fuel = collect($fuelData)->first(fn (array $fuel) => (strtolower((string) ($fuel['brandstof_omschrijving'] ?? '')) === 'elektriciteit') === $electric);

        if ($fuel === null || ! in_array(trans('powertrains')[$powertrain]['consumption_unit'] ?? null, ['l/100km', 'kWh/100km'], true)) {
            return null;
        }

        // Electricity in Wh/km, other fuels in l/100km.
        $candidates = $electric
            ? ['WLTP' => (float) ($fuel['elektrisch_verbruik_enkel_elektrisch_wltp'] ?? 0) / 10, 'NEDC' => (float) ($fuel['elektriciteitsverbruik_volledig_elektrisch'] ?? 0) / 10]
            : ['WLTP' => (float) ($fuel['brandstof_verbruik_gecombineerd_wltp'] ?? 0), 'NEDC' => (float) ($fuel['brandstofverbruik_gecombineerd'] ?? 0)];

        foreach ($candidates as $method => $value) {
            if ($value > 0) {
                return ['value' => round($value, 2), 'method' => $method];
            }
        }

        return null;
    }

    /**
     * Specifications of the vehicle worth knowing, as rows of the vehicle form.
     *
     * @param  array<string, string>  $vehicleData
     * @param  list<array<string, string>>  $fuelData
     * @return list<array{name: string, value: string, icon: string}>
     */
    public function getSpecifications(array $vehicleData, array $fuelData): array
    {
        $number = fn ($value, string $unit) => filled($value) && (float) $value > 0 ? number_format((float) $value, 0, ',', '.').' '.$unit : null;
        $fuel = collect($fuelData)->firstWhere('brandstof_volgnummer', '1') ?? ($fuelData[0] ?? []);
        $powertrain = $this->getPowertrainOptionFromRdwFuelData($fuelData);
        $consumption = $this->getFactoryConsumption($fuelData, $powertrain);
        $range = collect($fuelData)->pluck('actie_radius_enkel_elektrisch_wltp')->filter()->first();

        return collect([
            ['name' => __('Color'), 'value' => filled($vehicleData['eerste_kleur'] ?? null) ? ucfirst(strtolower($vehicleData['eerste_kleur'])) : null, 'icon' => 'mdi:palette'],
            ['name' => __('Seats'), 'value' => $vehicleData['aantal_zitplaatsen'] ?? null, 'icon' => 'mdi:car-seat'],
            ['name' => __('Curb weight'), 'value' => $number($vehicleData['massa_rijklaar'] ?? null, 'kg'), 'icon' => 'mdi:weight-kilogram'],
            ['name' => __('Towing capacity braked'), 'value' => $number($vehicleData['maximum_trekken_massa_geremd'] ?? null, 'kg'), 'icon' => 'mdi:truck-trailer'],
            ['name' => __('Towing capacity unbraked'), 'value' => $number($vehicleData['maximum_massa_trekken_ongeremd'] ?? null, 'kg'), 'icon' => 'mdi:truck-trailer'],
            ['name' => __('Catalog price'), 'value' => filled($vehicleData['catalogusprijs'] ?? null) ? '€ '.number_format((float) $vehicleData['catalogusprijs'], 0, ',', '.') : null, 'icon' => 'ic:round-local-offer'],
            ['name' => __('Energy label'), 'value' => $vehicleData['zuinigheidsclassificatie'] ?? null, 'icon' => 'fa7-solid:leaf'],
            ['name' => __('Emission class'), 'value' => $fuel['uitlaatemissieniveau'] ?? null, 'icon' => 'fa7-solid:leaf'],
            ['name' => __('Factory fuel consumption (:method)', ['method' => $consumption['method'] ?? '']), 'value' => $consumption
                ? number_format($consumption['value'], 1, ',', '.').' '.trans('powertrains')[$powertrain]['consumption_unit']
                : null, 'icon' => 'mdi:fuel'],
            ['name' => __('Electric range (WLTP)'), 'value' => $number($range, 'km'), 'icon' => 'mdi:map-marker-distance'],
        ])
            ->filter(fn (array $specification) => filled($specification['value']))
            ->map(fn (array $specification) => [...$specification, 'value' => (string) $specification['value']])
            ->values()
            ->all();
    }

    /**
     * A warning when the RDW judges the odometer readings of the vehicle illogical.
     *
     * @param  array<string, mixed>  $vehicleData
     */
    public function getOdometerWarning(array $vehicleData): ?string
    {
        if (($vehicleData['tellerstandoordeel'] ?? null) !== 'Onlogisch') {
            return null;
        }

        return __('According to the RDW the odometer readings of this vehicle are illogical (NAP). The mileage may have been tampered with.');
    }

    public function getOpenRecalls(string $licensePlate): array
    {
        $recalls = [];
        $openRecallsRdw = json_decode($this->fetchOpenRecallsByLicensePlate($licensePlate), true) ?: [];

        foreach ($openRecallsRdw as $openRecallRdw) {
            $referenceCodeRdw = $openRecallRdw['referentiecode_rdw'] ?? '';

            if (empty($referenceCodeRdw)) {
                continue;
            }

            $rdwRecallDetails = json_decode($this->fetchRecallByReferenceCode($referenceCodeRdw), true);

            if (! empty($rdwRecallDetails)) {
                $recalls[] = array_merge($openRecallRdw, $rdwRecallDetails[0]);
            }
        }

        return $recalls;
    }

    private function call(string $endpoint, array $params = []): string
    {
        try {
            $response = Http::timeout(5)
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36')
                ->retry(3, 100)
                ->get(config('rdw.base_url').$endpoint, $params);

            if ($response->ok()) {
                return $response->body();
            }

            return '';
        } catch (\Exception $e) {
            Log::channel('rdw-api')->warning('RDW API call failed: '.$e->getMessage());

            return '';
        }
    }
}
