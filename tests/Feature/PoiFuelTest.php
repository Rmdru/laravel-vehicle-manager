<?php

namespace Tests\Feature;

use App\Models\Poi;
use Tests\TestCase;

class PoiFuelTest extends TestCase
{
    public function test_a_gas_station_that_tells_its_fuels_without_the_one_of_the_vehicle_does_not_sell_it()
    {
        // An Autohof for trucks: diesel and AdBlue only.
        $autohof = new Poi(['kind' => Poi::GAS_STATION, 'tags' => ['amenity' => 'fuel', 'fuel:diesel' => 'yes', 'fuel:adblue' => 'yes']]);

        $this->assertFalse($autohof->sells(['Unleaded 95 (E10)']));
        $this->assertTrue($autohof->sells(['Diesel']));
    }

    public function test_a_gas_station_that_does_not_tell_its_fuels_is_taken_to_sell_them()
    {
        $station = new Poi(['kind' => Poi::GAS_STATION, 'tags' => ['amenity' => 'fuel', 'brand' => 'Shell']]);

        $this->assertTrue($station->sells(['Unleaded 95 (E10)']));
        $this->assertTrue($station->sells(['Diesel']));
        $this->assertTrue($station->sells(null));
    }

    public function test_any_of_the_fuel_types_of_the_vehicle_will_do()
    {
        $station = new Poi(['kind' => Poi::GAS_STATION, 'tags' => ['amenity' => 'fuel', 'fuel:octane_95' => 'yes', 'fuel:diesel' => 'yes']]);

        $this->assertTrue($station->sells(['Unleaded 95 (E10)', 'Unleaded 95 (E5)']));
        // Only the fuel types of the vehicle itself.
        $this->assertFalse($station->sells(['Unleaded 95 (E10)']));
        $this->assertFalse($station->sells(['LPG']));
    }

    public function test_a_gas_station_that_has_none_of_the_fuel_does_not_sell_it()
    {
        $station = new Poi(['kind' => Poi::GAS_STATION, 'tags' => ['amenity' => 'fuel', 'fuel:diesel' => 'no']]);

        $this->assertFalse($station->sells(['Diesel']));
        $this->assertTrue($station->sells(['Unleaded 95 (E10)']));
    }
}
