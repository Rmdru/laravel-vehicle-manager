<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Finding;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 */
class FindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_id' => Vehicle::factory(),
            'source' => 'garage_advice',
            'severity' => 'soon',
            'status' => 'open',
            'date' => $this->faker->dateTimeBetween('-1 year')->format('Y-m-d'),
            'title' => $this->faker->sentence(4),
        ];
    }
}
