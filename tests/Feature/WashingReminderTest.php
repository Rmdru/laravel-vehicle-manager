<?php

namespace Tests\Feature;

use App\Models\Reconditioning;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * At most one washing reminder: after frost the carwash, else by hand, with
 * the exterior protection only when that is due. Protection is not applied
 * in the cold.
 */
class WashingReminderTest extends TestCase
{
    use RefreshDatabase;

    public static function cases(): array
    {
        return [
            'frost, protection not due' => [-2, 10, 'carwash'],
            'frost, protection due' => [-2, 80, 'carwash'],
            'mild, protection not due' => [6, 10, 'self_washing'],
            'mild, protection due' => [6, 80, 'self_washing_protection'],
        ];
    }

    #[DataProvider('cases')]
    public function test_only_one_wash_is_reminded_of(int $minimumTemperature, int $protectionDaysAgo, string $expected)
    {
        $vehicle = $this->signInWithVehicle();
        Reconditioning::factory()->for($vehicle)->create(['type' => 'carwash', 'date' => now()->subDays(25)->toDateString()]);
        Reconditioning::factory()->for($vehicle)->create(['type' => 'exterior_protection', 'date' => now()->subDays($protectionDaysAgo)->toDateString()]);
        Cache::put('vehicle_'.$vehicle->id.'_historical_min_temps', ['daily' => ['temperature_2m_min' => [8, $minimumTemperature, 9]]]);

        $vehicle = Vehicle::find($vehicle->id);
        $due = array_keys(array_filter([
            'carwash' => $vehicle->carwash_status,
            'self_washing' => $vehicle->self_washing_status,
            'self_washing_protection' => $vehicle->self_washing_protection_status,
        ]));

        $this->assertSame([$expected], $due);
    }
}
