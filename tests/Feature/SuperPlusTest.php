<?php

namespace Tests\Feature;

use App\Models\Refueling;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuperPlusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Refuelings of 40 liters each, the newest last.
     *
     * @param  list<string>  $fuelTypes
     */
    private function refuel(Vehicle $vehicle, array $fuelTypes): void
    {
        foreach ($fuelTypes as $index => $fuelType) {
            Refueling::factory()->for($vehicle)->create([
                'date' => today()->subDays(count($fuelTypes) - $index)->toDateString(),
                'fuel_type' => $fuelType,
                'amount' => 40,
                'charge_time' => null,
            ]);
        }
    }

    public function test_super_plus_is_due_after_three_regular_refuelings()
    {
        $vehicle = $this->signInWithVehicle();
        $this->refuel($vehicle, ['Super Plus (E5)', 'Super Plus (E5)', 'Unleaded 95 (E10)', 'Unleaded 95 (E5)', 'Unleaded 95 (E10)']);

        // 40% premium, but the last three were regular.
        $this->assertTrue($vehicle->superPlusDue());
    }

    public function test_super_plus_is_due_when_premium_is_less_than_a_quarter()
    {
        $vehicle = $this->signInWithVehicle();
        $this->refuel($vehicle, ['Unleaded 95 (E10)', 'Unleaded 95 (E10)', 'Unleaded 95 (E10)', 'Unleaded 95 (E10)', 'Super Plus (E5)']);

        // The last one was Super Plus, but it is only 20% of the gasoline.
        $this->assertTrue($vehicle->superPlusDue());

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('notifications', fn ($notifications) => collect($notifications)->contains('key', 'periodic_super_plus'))
                    ->etc()));
    }

    public function test_super_plus_is_not_due_when_premium_is_a_quarter()
    {
        $vehicle = $this->signInWithVehicle();
        $this->refuel($vehicle, ['Unleaded 95 (E10)', 'Unleaded 95 (E10)', 'Unleaded 95 (E10)', 'Super Plus (E5)']);

        $this->assertFalse($vehicle->superPlusDue());
    }

    public function test_super_plus_is_not_due_before_the_fourth_refueling()
    {
        $vehicle = $this->signInWithVehicle();
        $this->refuel($vehicle, ['Unleaded 95 (E10)', 'Unleaded 95 (E10)', 'Unleaded 95 (E10)']);

        $this->assertFalse($vehicle->superPlusDue());

        $this->refuel($vehicle, ['Unleaded 95 (E10)']);

        $this->assertTrue($vehicle->superPlusDue());
    }

    public function test_super_plus_is_not_due_when_premium_is_more_than_a_quarter()
    {
        $vehicle = $this->signInWithVehicle();
        // LPG and electricity are no gasoline: 1 in 3 of the gasoline is premium.
        $this->refuel($vehicle, ['Unleaded 95 (E10)', 'LPG', 'Unleaded 95 (E10)', 'Electricity AC', 'Super Plus (E5)']);

        $this->assertFalse($vehicle->superPlusDue());
    }

    public function test_super_plus_is_never_due_without_gasoline()
    {
        $vehicle = $this->signInWithVehicle(['powertrain' => 'diesel']);
        $this->refuel($vehicle, ['Diesel', 'Diesel', 'Diesel']);

        $this->assertFalse($vehicle->superPlusDue());
    }

    public function test_super_plus_is_not_due_without_refuelings()
    {
        $this->assertFalse($this->signInWithVehicle()->superPlusDue());
    }
}
