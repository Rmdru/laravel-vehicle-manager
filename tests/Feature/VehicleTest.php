<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
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
            'powertrain' => 'gasoline',
            'fuel_types' => ['Unleaded 95 (E10)'],
            'tank_capacity' => 50,
            'maintenance_interval_distance' => 15000,
            'maintenance_interval_time' => 12,
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

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('currentVehicle.id', $vehicle->id));
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
            ->assertInertia(fn (Assert $page) => $page->component('Crud/Show')->has('sections', 3));

        $this->get(route('vehicles.edit', $vehicle))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('form.defaults.model', $vehicle->model));

        $this->patch(route('vehicles.update', $vehicle), $this->validData(['model' => 'A4']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('vehicles.index'));

        $this->assertSame('A4', $vehicle->refresh()->model);
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

        [$width, $height] = getimagesizefromstring(Storage::disk('private')->get("vehicles/{$vehicle->id}.webp"));

        $this->assertSame([1600, 1200], [$width, $height]);
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
            ->assertJson([
                'brand' => array_search('Audi', config('vehicles.brands'), true),
                'model' => 'A3',
                'engine' => '1395 cc 4 cylinder 110 kW',
                'purchase_date' => '2024-01-10',
                'construction_date' => '2018-05-01',
                'country_registration' => 'netherlands',
                'powertrain' => 'gasoline',
            ]);

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
