<?php

namespace Tests;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Vehicle statuses consult external services (RDW, weather, IP location).
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Sign in as a user that owns a drivable gasoline vehicle.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function signInWithVehicle(array $attributes = []): Vehicle
    {
        $user = User::factory()->create();

        $vehicle = Vehicle::factory()->for($user)->create([
            'powertrain' => 'gasoline',
            'fuel_types' => ['Unleaded 95 (E10)', 'Super Plus (E5)'],
            'country_registration' => 'netherlands',
            'status' => 'drivable',
            'tank_capacity' => 50,
            'mileage_start' => 1000,
            'mileage_latest' => 1000,
            'rdw_data' => null,
            ...$attributes,
        ]);

        $this->actingAs($user);

        return $vehicle;
    }
}
