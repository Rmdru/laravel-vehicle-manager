<?php

namespace Tests\Feature;

use App\Models\Refueling;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PremiumFuelRatioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, float>  $amounts  Liters (or kWh) by fuel type.
     */
    private function refuel(Vehicle $vehicle, array $amounts): void
    {
        foreach ($amounts as $fuelType => $amount) {
            Refueling::factory()->for($vehicle)->create([
                'date' => today()->subDay()->toDateString(),
                'fuel_type' => $fuelType,
                'amount' => $amount,
                'charge_time' => null,
            ]);
        }
    }

    private function assertRatio(string $title, string $value): void
    {
        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('stats', fn ($stats) => collect($stats)->firstWhere('title', $title)['value'] === $value)
                    ->etc()));
    }

    public function test_fast_charging_is_the_premium_of_an_electric_vehicle()
    {
        $vehicle = $this->signInWithVehicle(['powertrain' => 'electricity']);
        $this->refuel($vehicle, ['Electricity AC' => 30, 'Electricity DC' => 10]);

        $this->assertRatio('Ratio AC charging vs DC charging', '25 %');
    }

    public function test_lpg_and_cng_do_not_count_for_the_premium_gasoline()
    {
        $vehicle = $this->signInWithVehicle(['powertrain' => 'gasoline_lpg']);
        $this->refuel($vehicle, ['Unleaded 95 (E10)' => 30, 'Super Plus (E5)' => 10, 'LPG' => 60, 'CNG' => 20, 'Electricity AC' => 15]);

        $this->assertRatio('Ratio premium gasoline vs Unleaded 95', '25 %');
    }

    public function test_premium_diesel_is_the_premium_of_a_diesel()
    {
        $vehicle = $this->signInWithVehicle(['powertrain' => 'diesel_adblue']);
        $this->refuel($vehicle, ['Diesel' => 45, 'Premium diesel' => 15, 'Adblue' => 10]);

        $this->assertRatio('Ratio premium diesel vs regular diesel', '25 %');
    }
}
