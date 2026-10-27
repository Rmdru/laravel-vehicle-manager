<?php

namespace Tests\Feature;

use App\Enums\Transmission;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DashboardService;
use App\Services\VehicleStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return [
            'license_plate' => 'AB-123-C',
            'brand' => 3,
            'model' => 'A3',
            'version' => 'Sportback',
            'engine' => '1.4 TFSI',
            'transmission' => 'manual',
            'powertrain' => 'gasoline',
            'fuel_types' => ['Unleaded 95 (E10)'],
            'tank_capacity' => 50,
            'maintenance_intervals' => [
                ['types' => ['inspection_service'], 'km' => 60000, 'months' => 24],
                ['types' => ['oil_service'], 'km' => 15000, 'months' => 12],
            ],
            'mileage_start' => 50000,
            'purchase_date' => '2024-01-10',
            'construction_date' => '2018-05-01',
            'purchase_price' => 15000,
            'country_registration' => 'netherlands',
            'status' => 'drivable',
            'specifications' => [['name' => 'Color', 'value' => 'Blue', 'icon' => null]],
            'notifications__maintenance__apk' => '0',
            ...$overrides,
        ];
    }

    public function test_a_user_without_vehicles_can_open_the_form_for_their_first_vehicle()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('vehicles.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Crud/Form')
                ->where('variant', 'vehicle')
                ->whereNot('intro', null));
    }

    public function test_a_vehicle_can_be_created_and_becomes_the_current_vehicle()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('vehicles.store'), $this->validData())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('vehicles.index'));

        $vehicle = $user->vehicles()->sole();

        $this->assertSame('A3', $vehicle->model);
        $this->assertSame(['Unleaded 95 (E10)'], $vehicle->fuel_types);
        $this->assertFalse($vehicle->notifications['maintenance']['apk']);
        $this->assertTrue($vehicle->notifications['maintenance']['recall']);
        $this->assertSame([['types' => ['inspection_service'], 'km' => 60000, 'months' => 24], ['types' => ['oil_service'], 'km' => 15000, 'months' => 12]], $vehicle->maintenanceIntervals());

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('currentVehicle.id', $vehicle->id));
    }

    public function test_an_interval_can_be_restarted_by_the_airco_check()
    {
        $vehicle = $this->signInWithVehicle(['maintenance_intervals' => [['types' => ['airco_check'], 'km' => null, 'months' => 24]]]);
        $vehicle->maintenances()->create(['type_maintenance' => 'maintenance', 'date' => '2026-01-10', 'mileage' => 1000, 'total_price' => 300, 'airco_check' => false]);
        $vehicle->maintenances()->create(['type_maintenance' => null, 'date' => '2025-05-01', 'mileage' => 900, 'total_price' => 90, 'airco_check' => true]);

        $next = $vehicle->fresh()->nextMaintenance();

        $this->assertSame('Airco check', $next['label']);
        $this->assertSame('2027-05-01', $next['date']->toDateString());
        $this->assertArrayHasKey('airco_check', Vehicle::intervalTypeOptions());
        // No card of its own at the top of the dashboard.
        $this->assertNotContains('Airco check', array_column(app(DashboardService::class)->statuses($vehicle->fresh()), 'title'));
    }

    public function test_the_maintenance_notification_names_the_interval_that_is_due()
    {
        $vehicle = $this->signInWithVehicle(['maintenance_intervals' => [
            ['types' => ['inspection_service'], 'km' => null, 'months' => 24],
            ['types' => ['oil_service'], 'km' => null, 'months' => 12],
        ]]);
        $vehicle->maintenances()->create(['type_maintenance' => 'inspection_service', 'date' => now()->subMonths(2)->toDateString(), 'mileage' => 1000, 'total_price' => 400]);
        $vehicle->maintenances()->create(['type_maintenance' => 'oil_service', 'date' => now()->subMonths(11)->subDays(15)->toDateString(), 'mileage' => 1000, 'total_price' => 150]);

        $notification = collect((new VehicleStatusService)->getNotifications($vehicle->fresh()))->firstWhere('key', 'maintenance');

        $this->assertSame('Oil service required now', $notification['text']);
    }

    public function test_each_maintenance_interval_is_restarted_by_its_own_maintenance()
    {
        $this->travelTo('2026-10-23 12:00:00');
        $vehicle = $this->signInWithVehicle(['mileage_latest' => 20000, 'maintenance_intervals' => [
            ['types' => ['inspection_service'], 'km' => null, 'months' => 24],
            ['types' => ['oil_service', 'inspection_service'], 'km' => null, 'months' => 12],
        ]]);
        $vehicle->maintenances()->create(['type_maintenance' => 'inspection_service', 'date' => '2025-06-01', 'mileage' => 10000, 'total_price' => 400]);
        $vehicle->maintenances()->create(['type_maintenance' => 'oil_service', 'date' => '2026-03-01', 'mileage' => 15000, 'total_price' => 150]);

        $next = $vehicle->fresh()->nextMaintenances();

        // The oil service is restarted by the oil service, the inspection service only by itself; the first due first.
        $this->assertSame(['Oil service / Inspection service', 'Inspection service'], array_column($next, 'label'));
        $this->assertSame(['2027-03-01', '2027-06-01'], array_map(fn (array $item) => $item['date']->toDateString(), $next));
        $this->assertSame(['Oil service / Inspection service', 'Inspection service'], array_slice(array_column(app(DashboardService::class)->statuses($vehicle->fresh()), 'title'), 0, 2));
    }

    public function test_creating_a_vehicle_requires_the_mandatory_fields()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('vehicles.store'), [])
            ->assertSessionHasErrors(['license_plate', 'brand', 'model', 'version', 'powertrain', 'fuel_types', 'construction_date', 'country_registration']);
    }

    public function test_the_garage_only_lists_the_vehicles_of_the_user()
    {
        $vehicle = $this->signInWithVehicle();
        Vehicle::factory()->create();

        $this->get(route('vehicles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicles/Index')
                ->has('vehicles', 1)
                ->where('vehicles.0.id', $vehicle->id)
                ->loadDeferredProps(fn (Assert $reload) => $reload->has('badges.'.$vehicle->id)->etc()));
    }

    public function test_a_vehicle_can_be_viewed_and_updated()
    {
        $vehicle = $this->signInWithVehicle();

        $this->get(route('vehicles.show', $vehicle))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Crud/Show')->has('sections', 4));

        $this->get(route('vehicles.edit', $vehicle))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('form.defaults.model', $vehicle->model));

        $this->patch(route('vehicles.update', $vehicle), $this->validData(['model' => 'A4', 'body_type' => 'station_wagon']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('vehicles.index'));

        $this->assertSame('A4', $vehicle->refresh()->model);
        $this->assertSame('station_wagon', $vehicle->body_type);

        $this->patch(route('vehicles.update', $vehicle), $this->validData(['transmission' => 'automatic']))
            ->assertSessionHasNoErrors();
        $this->assertSame(Transmission::Automatic, $vehicle->refresh()->transmission);

        $this->patch(route('vehicles.update', $vehicle), $this->validData(['transmission' => 'invalid']))
            ->assertSessionHasErrors('transmission');

        $this->patch(route('vehicles.update', $vehicle), $this->validData(['body_type' => 'limousine']))
            ->assertSessionHasErrors('body_type');
    }

    public function test_an_electric_vehicle_is_always_automatic()
    {
        $vehicle = $this->signInWithVehicle(['powertrain' => 'electricity', 'transmission' => 'manual']);

        $this->assertSame(Transmission::Automatic, $vehicle->transmission);
    }

    public function test_the_push_notifications_are_chosen_per_vehicle_in_its_form()
    {
        $vehicle = $this->signInWithVehicle();

        $this->get(route('vehicles.edit', $vehicle))
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.defaults.push__info', true)
                ->where('form.defaults.push__quiet_from', '21:00'));

        $this->patch(route('vehicles.update', $vehicle), $this->validData([
            'push__info' => '0',
            'push__moment' => '19:30',
            'push__quiet_from' => '',
            'push__quiet_until' => '',
        ]))->assertSessionHasNoErrors();

        $preferences = $vehicle->refresh()->pushPreferences();
        $this->assertFalse($preferences['info']);
        $this->assertTrue($preferences['critical']);
        $this->assertSame('19:30', $preferences['moment']);
        // Emptied: no quiet hours.
        $this->assertNull($preferences['quiet_from']);
    }

    public function test_an_image_can_be_uploaded_and_is_only_served_to_the_owner()
    {
        Storage::fake('private');

        $vehicle = $this->signInWithVehicle();

        $this->post(route('vehicles.update', $vehicle), $this->validData([
            '_method' => 'PATCH',
            'image' => UploadedFile::fake()->image('car.png'),
        ]))->assertSessionHasNoErrors();

        Storage::disk('private')->assertExists("vehicles/{$vehicle->id}.webp");

        $this->get(route('vehicle.image', $vehicle))->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('vehicle.image', $vehicle))
            ->assertNotFound();
    }

    public function test_a_large_image_is_stored_no_larger_than_it_is_shown()
    {
        Storage::fake('private');

        $vehicle = $this->signInWithVehicle();

        $this->post(route('vehicles.update', $vehicle), $this->validData([
            '_method' => 'PATCH',
            'image' => UploadedFile::fake()->image('car.jpg', 4000, 3000),
        ]))->assertSessionHasNoErrors();

        $size = fn (string $path) => array_slice(getimagesizefromstring(Storage::disk('private')->get($path)), 0, 2);

        $this->assertSame([1600, 1200], $size("vehicles/{$vehicle->id}.webp"));
        $this->assertSame([320, 240], $size("vehicles/{$vehicle->id}-320.webp"));

        // The browser picks the width closest to the size the image is shown at.
        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentVehicle.imageSrcset', fn (string $srcset) => str_contains($srcset, 'width=320')
                    && str_contains($srcset, ' 320w, ')
                    && str_contains($srcset, ' 1280w, ')
                    && str_ends_with($srcset, ' 1600w')));

        $served = fn (array $query) => getimagesizefromstring($this->get(route('vehicle.image', [$vehicle, ...$query]))->streamedContent())[0];

        $this->assertSame(640, $served(['width' => 640]));
        $this->assertSame(1600, $served([]));
        // A width the image is not stored at falls back to the largest.
        $this->assertSame(1600, $served(['width' => 999]));

        // Replacing it by a small image leaves no widths of the previous one behind.
        $this->post(route('vehicles.update', $vehicle), $this->validData([
            '_method' => 'PATCH',
            'image' => UploadedFile::fake()->image('car.png', 300, 200),
        ]))->assertSessionHasNoErrors();

        Storage::disk('private')->assertMissing("vehicles/{$vehicle->id}-320.webp");
        $this->assertSame([300, 200], $size("vehicles/{$vehicle->id}.webp"));
    }

    public function test_vehicles_of_other_users_are_not_accessible()
    {
        $this->signInWithVehicle();
        $other = Vehicle::factory()->create();

        $this->get(route('vehicles.show', $other))->assertNotFound();
        $this->get(route('vehicles.edit', $other))->assertNotFound();
        $this->patch(route('vehicles.update', $other), $this->validData())->assertNotFound();
        $this->post(route('vehicles.select', $other))->assertNotFound();
        $this->delete(route('vehicles.destroy', $other))->assertNotFound();

        $this->assertNotSoftDeleted($other);
    }

    public function test_the_current_vehicle_can_be_switched()
    {
        $newest = $this->signInWithVehicle();
        $older = Vehicle::factory()->for($newest->user)->create(['created_at' => now()->subYear()]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('currentVehicle.id', $newest->id)->has('vehicles', 2));

        $this->post(route('vehicles.select', $older))->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('currentVehicle.id', $older->id));
    }

    public function test_a_vehicle_can_be_deleted()
    {
        $vehicle = $this->signInWithVehicle();

        $this->delete(route('vehicles.destroy', $vehicle))->assertRedirect(route('vehicles.index'));

        $this->assertSoftDeleted($vehicle);
        $this->get(route('dashboard'))->assertRedirect(route('vehicles.create'));
    }

    public function test_the_rdw_lookup_returns_values_for_the_vehicle_form()
    {
        $this->signInWithVehicle();

        Http::swap(new Factory);
        Http::fake([
            '*m9d7-ebf2.json*' => Http::response([[
                'merk' => 'AUDI',
                'handelsbenaming' => 'A3',
                'type' => '8V',
                'cilinderinhoud' => '1395',
                'aantal_cilinders' => '4',
                'datum_tenaamstelling' => '20240110',
                'datum_eerste_toelating' => '20180501',
            ]]),
            '*8ys7-d773.json*' => Http::response([[
                'brandstof_omschrijving' => 'Benzine',
                'nettomaximumvermogen' => '110.00',
            ]]),
        ]);

        $this->getJson(route('vehicles.rdw-lookup', ['license_plate' => 'ab-123-c']))
            ->assertOk()
            ->assertJson(['values' => [
                'brand' => array_search('Audi', config('vehicles.brands'), true),
                'model' => 'A3',
                'engine' => '1395 cc 4 cylinder 110 kW',
                'purchase_date' => '2024-01-10',
                'construction_date' => '2018-05-01',
                'country_registration' => 'netherlands',
                'powertrain' => 'gasoline',
                'fuel_types' => ['Unleaded 95 (E10)', 'Super Plus (E5)'],
            ], 'warning' => null]);

        Http::assertSent(fn ($request) => $request['kenteken'] === 'AB123C');
    }

    public function test_the_rdw_lookup_returns_nothing_for_an_unknown_license_plate()
    {
        $this->signInWithVehicle();

        $this->getJson(route('vehicles.rdw-lookup', ['license_plate' => 'XX-000-X']))
            ->assertOk()
            ->assertExactJson([]);
    }
}
