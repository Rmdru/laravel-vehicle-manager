<?php

namespace Tests\Feature;

use App\Enums\BodyType;
use App\Http\Middleware\ResolveCurrentVehicle;
use App\Models\Maintenance;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Services\DashboardService;
use App\Services\RdwService;
use App\Services\VehicleStatusService;
use App\Support\CurrentVehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RdwTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->travelTo('2026-10-16 12:00:00');
    }

    /**
     * @param  array<string, string>  $vehicleData
     */
    private function fakeRdw(array $vehicleData, array $fuel = [], array $defects = [], array $descriptions = []): void
    {
        Http::fake([
            '*m9d7-ebf2.json*' => Http::response($vehicleData === [] ? [] : [['kenteken' => 'AB123CD', ...$vehicleData]]),
            '*8ys7-d773.json*' => Http::response($fuel),
            '*a34c-vvps.json*' => Http::response($defects),
            '*hx2c-gt7k.json*' => Http::response($descriptions),
            '*t49b-isb7.json*' => Http::response([['referentiecode_rdw' => 'RC1']]),
            '*j9yg-7rg9.json*' => Http::response([['description' => 'Recall']]),
            // Server-side rendering, when the dev server runs.
            '*__inertia_ssr*' => Http::response(null, 404),
        ]);
    }

    private function vehicle(array $rdwData = [], array $attributes = []): Vehicle
    {
        return Vehicle::factory()->create([
            'license_plate' => 'AB-123-CD',
            'status' => 'drivable',
            'powertrain' => 'gasoline',
            'purchase_date' => '2023-01-01',
            'rdw_data' => $rdwData,
            ...$attributes,
        ]);
    }

    public function test_open_recalls_are_only_looked_up_when_the_rdw_indicates_there_are_any()
    {
        $vehicle = $this->vehicle();
        $this->fakeRdw(['openstaande_terugroepactie_indicator' => 'Nee']);

        $this->artisan('import:vehicle-data')->assertExitCode(0);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 't49b-isb7'));
        $this->assertSame([], $vehicle->refresh()->rdw_data['open_recalls']);
    }

    public function test_the_import_keeps_the_fuel_data_and_the_defects_of_the_latest_inspection()
    {
        $vehicle = $this->vehicle();
        $this->fakeRdw(
            ['openstaande_terugroepactie_indicator' => 'Ja'],
            fuel: [['brandstof_volgnummer' => '1', 'brandstof_omschrijving' => 'Benzine', 'brandstof_verbruik_gecombineerd_wltp' => '5.400']],
            defects: [
                ['meld_datum_door_keuringsinstantie' => '20261010', 'gebrek_identificatie' => '576', 'soort_erkenning_omschrijving' => 'APK Lichte voertuigen', 'aantal_gebreken_geconstateerd' => '2'],
                ['meld_datum_door_keuringsinstantie' => '20251001', 'gebrek_identificatie' => '430', 'soort_erkenning_omschrijving' => 'APK Lichte voertuigen', 'aantal_gebreken_geconstateerd' => '1'],
            ],
            descriptions: [['gebrek_identificatie' => '576', 'ingangsdatum_gebrek' => '20170401', 'gebrek_artikel_nummer' => '5.*.53', 'gebrek_omschrijving' => 'Richtingaanwijzers kleur wijkt af']],
        );

        $this->artisan('import:vehicle-data')->assertExitCode(0);

        $rdwData = $vehicle->refresh()->rdw_data;
        $this->assertSame('RC1', $rdwData['open_recalls'][0]['referentiecode_rdw']);
        $this->assertSame('5.400', $rdwData['fuel'][0]['brandstof_verbruik_gecombineerd_wltp']);
        $this->assertSame([
            'date' => '2026-10-10',
            'type' => 'APK Lichte voertuigen',
            'defects' => [['code' => '576', 'article' => '5.*.53', 'description' => 'Richtingaanwijzers kleur wijkt af', 'count' => 2]],
        ], $rdwData['inspection']);
    }

    public function test_a_new_mot_asks_for_a_maintenance_until_one_is_added()
    {
        $vehicle = $this->vehicle(['vervaldatum_apk_dt' => '2026-11-01T00:00:00.000', 'datum_tenaamstelling' => '20230101']);
        $this->fakeRdw(
            ['vervaldatum_apk_dt' => '2027-10-12T00:00:00.000', 'datum_tenaamstelling' => '20230101', 'openstaande_terugroepactie_indicator' => 'Nee'],
            defects: [['meld_datum_door_keuringsinstantie' => '20261012', 'gebrek_identificatie' => '576', 'soort_erkenning_omschrijving' => 'APK Lichte voertuigen', 'aantal_gebreken_geconstateerd' => '1']],
            descriptions: [['gebrek_identificatie' => '576', 'ingangsdatum_gebrek' => '20170401', 'gebrek_artikel_nummer' => '5.*.53', 'gebrek_omschrijving' => 'Richtingaanwijzers kleur wijkt af']],
        );

        $this->artisan('import:vehicle-data')->assertExitCode(0);
        $vehicle->refresh();

        $this->assertSame(['previous_expiry' => '2026-11-01', 'expiry' => '2027-10-12', 'detected_at' => '2026-10-16'], $vehicle->rdw_data['apk_renewed']);
        $this->assertArrayNotHasKey('ownership', $this->notifications($vehicle));

        $notification = $this->notifications($vehicle)['apk_renewed'];
        $this->assertSame(route('maintenances.create', ['rdw_apk' => 1]), $notification['url']);
        $this->assertSame('Richtingaanwijzers kleur wijkt af', $notification['data'][1]['defect']);

        $this->actingAs($vehicle->user)
            ->withSession([ResolveCurrentVehicle::SESSION_KEY => $vehicle->id])
            ->get(route('maintenances.create', ['rdw_apk' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.defaults.apk', true)
                ->where('form.defaults.date', '2026-10-12')
                ->where('form.defaults.description', "MOT\nDefects found by the inspection:\n- Richtingaanwijzers kleur wijkt af")
                ->etc());

        Maintenance::factory()->for($vehicle)->create(['apk' => true, 'date' => '2026-10-12']);

        $this->assertArrayNotHasKey('apk_renewed', $this->notifications($vehicle->refresh()));
    }

    public function test_a_new_registration_after_the_purchase_asks_whether_the_vehicle_was_sold()
    {
        $vehicle = $this->vehicle(['datum_tenaamstelling' => '20230101']);
        $this->fakeRdw(['datum_tenaamstelling' => '20261015', 'openstaande_terugroepactie_indicator' => 'Nee']);

        $this->artisan('import:vehicle-data')->assertExitCode(0);
        $vehicle->refresh();

        $this->assertSame('2026-10-15', $vehicle->rdw_data['ownership_changed_at']);
        $this->assertSame('warning', $this->notifications($vehicle)['ownership']['type']);

        // The user bought it again, or corrected the purchase date.
        $vehicle->update(['purchase_date' => '2026-10-15']);

        $this->assertArrayNotHasKey('ownership', $this->notifications($vehicle->refresh()));
    }

    public function test_an_exported_vehicle_or_one_no_longer_registered_is_reported()
    {
        $exported = $this->vehicle(['export_indicator' => 'Ja']);

        $this->assertArrayHasKey('export', $this->notifications($exported));

        $gone = $this->vehicle(['kenteken' => 'AB123CD', 'export_indicator' => 'Nee']);
        $exported->forceDelete();
        $this->fakeRdw([]);

        $this->artisan('import:vehicle-data')->assertExitCode(0);
        $gone->refresh();

        $this->assertSame('2026-10-16', $gone->rdw_data['not_found_since']);
        $this->assertSame('AB123CD', $gone->rdw_data['kenteken']);
        $this->assertArrayHasKey('export', $this->notifications($gone));
    }

    public function test_the_rdw_lookup_fills_in_a_hybrid_and_warns_about_illogical_odometer_readings()
    {
        $this->signInWithVehicle();
        $this->fakeRdw(
            ['merk' => 'TOYOTA', 'handelsbenaming' => 'COROLLA', 'eerste_kleur' => 'GRIJS', 'aantal_zitplaatsen' => '5', 'massa_rijklaar' => '1420', 'tellerstandoordeel' => 'Onlogisch'],
            fuel: [
                ['brandstof_volgnummer' => '1', 'brandstof_omschrijving' => 'Elektriciteit', 'klasse_hybride_elektrisch_voertuig' => 'NOVC-HEV'],
                ['brandstof_volgnummer' => '2', 'brandstof_omschrijving' => 'Benzine', 'klasse_hybride_elektrisch_voertuig' => 'NOVC-HEV', 'brandstof_verbruik_gecombineerd_wltp' => '4.500'],
            ],
        );

        $this->getJson(route('vehicles.rdw-lookup', ['license_plate' => 'AB-123-CD']))
            ->assertOk()
            ->assertJsonPath('values.powertrain', 'gasoline_full_hybrid')
            // The RDW registers most hatchbacks as something else: left to the owner.
            ->assertJsonMissingPath('values.body_type')
            // Of a hybrid the RDW only knows the engine and the electric motor apart.
            ->assertJsonMissingPath('values.power_hp')
            ->assertJsonPath('values.fuel_types', ['Unleaded 95 (E10)', 'Super Plus (E5)'])
            ->assertJsonPath('values.specifications', [
                ['name' => 'Color', 'value' => 'Grijs', 'icon' => 'mdi:palette'],
                ['name' => 'Seats', 'value' => '5', 'icon' => 'mdi:car-seat'],
                ['name' => 'Curb weight', 'value' => '1.420 kg', 'icon' => 'mdi:weight-kilogram'],
                ['name' => 'Factory fuel consumption (WLTP)', 'value' => '4,5 l/100km', 'icon' => 'mdi:fuel'],
            ])
            ->assertJsonPath('warning', 'According to the RDW the odometer readings of this vehicle are illogical (NAP). The mileage may have been tampered with.');
    }

    public function test_the_body_type_only_comes_from_what_the_rdw_registers_reliably()
    {
        $rdw = app(RdwService::class);

        // Most hatchbacks are registered as a station wagon: by the trade name only.
        $this->assertNull($rdw->getBodyType(['merk' => 'SEAT', 'handelsbenaming' => 'LEON', 'inrichting' => 'stationwagen']));
        $this->assertNull($rdw->getBodyType(['merk' => 'FORD', 'handelsbenaming' => 'KUGA', 'inrichting' => 'MPV']));
        $this->assertSame(BodyType::StationWagon, $rdw->getBodyType(['merk' => 'SEAT', 'handelsbenaming' => 'LEON ST', 'inrichting' => 'MPV']));
        $this->assertSame(BodyType::StationWagon, $rdw->getBodyType(['merk' => 'OPEL', 'handelsbenaming' => 'ASTRA SPORTS TOURER+', 'inrichting' => 'stationwagen']));
        $this->assertSame(BodyType::StationWagon, $rdw->getBodyType(['merk' => 'AUDI', 'handelsbenaming' => 'A4 AVANT', 'inrichting' => 'stationwagen']));
        $this->assertNull($rdw->getBodyType(['merk' => 'FORD', 'handelsbenaming' => 'FOCUS ST', 'inrichting' => 'hatchback']));
        $this->assertSame(BodyType::Sedan, $rdw->getBodyType(['merk' => 'BMW', 'handelsbenaming' => '3ER REIHE', 'inrichting' => 'sedan']));
        $this->assertSame(BodyType::Convertible, $rdw->getBodyType(['merk' => 'MINI', 'handelsbenaming' => 'COOPER', 'inrichting' => 'cabriolet']));
        $this->assertSame(BodyType::Van, $rdw->getBodyType(['merk' => 'MERCEDES-BENZ', 'handelsbenaming' => '513CDI', 'inrichting' => 'open laadvloer', 'voertuigsoort' => 'Bedrijfsauto']));
    }

    public function test_the_power_comes_from_the_rdw_except_for_a_hybrid()
    {
        $rdw = app(RdwService::class);

        // Sold as 130 hp, which is 95.6 kW: not the 131 hp 96 kW is.
        $this->assertSame(130, $rdw->getPowerHp([['brandstof_omschrijving' => 'Benzine', 'nettomaximumvermogen' => '96.00']]));
        $this->assertSame(136, $rdw->getPowerHp([['brandstof_omschrijving' => 'Diesel', 'nettomaximumvermogen' => '100.00']]));
        $this->assertSame(98, $rdw->getPowerHp([['brandstof_omschrijving' => 'Benzine', 'nettomaximumvermogen' => '72.00']]));
        $this->assertSame(320, $rdw->getPowerHp([['brandstof_omschrijving' => 'Elektriciteit', 'netto_max_vermogen_elektrisch' => '235.00']]));
        $this->assertNull($rdw->getPowerHp([
            ['brandstof_omschrijving' => 'Benzine', 'nettomaximumvermogen' => '110.00', 'klasse_hybride_elektrisch_voertuig' => 'OVC-HEV'],
            ['brandstof_omschrijving' => 'Elektriciteit', 'netto_max_vermogen_elektrisch' => '80.00', 'klasse_hybride_elektrisch_voertuig' => 'OVC-HEV'],
        ]));
        $this->assertNull($rdw->getPowerHp([]));
    }

    public function test_the_import_fills_in_the_body_type_and_the_power_unless_the_owner_did()
    {
        $unknown = $this->vehicle();
        $chosen = $this->vehicle(attributes: ['license_plate' => 'XX-999-X', 'body_type' => 'hatchback', 'power_hp' => 150]);
        $this->fakeRdw(
            ['merk' => 'SEAT', 'handelsbenaming' => 'LEON ST', 'inrichting' => 'stationwagen', 'openstaande_terugroepactie_indicator' => 'Nee'],
            fuel: [['brandstof_volgnummer' => '1', 'brandstof_omschrijving' => 'Benzine', 'nettomaximumvermogen' => '96.00']],
        );

        $this->artisan('import:vehicle-data')->assertExitCode(0);

        $this->assertSame('station_wagon', $unknown->refresh()->body_type);
        $this->assertSame(130, $unknown->power_hp);
        $this->assertSame('hatchback', $chosen->refresh()->body_type);
        $this->assertSame(150, $chosen->power_hp);
    }

    public function test_the_edit_form_warns_about_illogical_odometer_readings()
    {
        $vehicle = $this->signInWithVehicle(['rdw_data' => ['tellerstandoordeel' => 'Onlogisch']]);
        $this->fakeRdw([]);

        $this->get(route('vehicles.edit', $vehicle))
            ->assertInertia(fn (Assert $page) => $page->where('warning', fn ($warning) => str_contains((string) $warning, 'illogical'))->etc());
    }

    public function test_the_dashboard_compares_the_fuel_usage_with_the_factory_specification()
    {
        $vehicle = $this->signInWithVehicle(['rdw_data' => ['fuel' => [
            ['brandstof_volgnummer' => '1', 'brandstof_omschrijving' => 'Benzine', 'brandstofverbruik_gecombineerd' => '5.00'],
        ]]]);

        Refueling::factory()->for($vehicle)->create(['date' => '2026-09-01', 'fuel_consumption' => 6, 'mileage_begin' => 1000, 'mileage_end' => 1500, 'total_price' => 50]);
        Refueling::factory()->for($vehicle)->create(['date' => '2026-10-01', 'fuel_consumption' => 5.5, 'mileage_begin' => 1500, 'mileage_end' => 2000, 'total_price' => 40]);

        CurrentVehicle::set($vehicle);
        $stats = collect(app(DashboardService::class)->stats($vehicle))->keyBy('title');

        $comparison = $stats['Fuel usage compared to specification (5,0 l/100km)'];
        $this->assertSame('15 %', $comparison['value']);
        $this->assertSame('10 %', $comparison['latest']);
        $this->assertSame('success', $comparison['color']);

        $fuelCosts = $stats['Average fuel costs per kilometer'];
        $this->assertSame('€ 0,09 /km', $fuelCosts['value']);
        $this->assertSame('€ 0,08 /km', $fuelCosts['latest']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function notifications(Vehicle $vehicle): array
    {
        return collect(app(VehicleStatusService::class)->getNotifications($vehicle))->keyBy('key')->all();
    }
}
