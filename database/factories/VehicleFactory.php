<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VehicleStatus;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $brands = config('vehicles.brands');
        $powertrains = trans('powertrains');
        $countries = config('countries');
        $fuelTypes = trans('fuel_types');
        $notifications = config('default_notification_settings');

        return [
            'id' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'brand' => $this->faker->randomElement(array_keys($brands)),
            'model' => $this->faker->word(),
            'version' => $this->faker->word(),
            'engine' => $this->faker->randomElement([null, $this->faker->word()]),
            'mileage_start' => $this->faker->randomElement([
                null,
                $this->faker->numberBetween(0, 500000),
            ]),
            'mileage_latest' => function (array $attributes) {
                return $this->faker->randomElement([
                    null,
                    $this->faker->numberBetween($attributes['mileage_start'] ?? 0, 500000),
                ]);
            },
            'purchase_date' => $this->faker->randomElement([
                null,
                $this->faker->dateTimeBetween()->format('Y-m-d'),
            ]),
            'construction_date' => function (array $attributes) {
                return $this->faker->dateTimeBetween(endDate: $attributes['purchase_date'] ?? 'now')->format('Y-m-d');
            },
            'purchase_price' => $this->faker->randomFloat(2, 0, 300000),
            'license_plate' => $this->faker->randomElement([
                strtoupper($this->faker->bothify('??-###-?')),
                strtoupper($this->faker->bothify('##-??-##')),
                strtoupper($this->faker->bothify('##-???-#')),
                strtoupper($this->faker->bothify('???-##-?')),
                strtoupper($this->faker->bothify('#-???-##')),
                strtoupper($this->faker->bothify('?-###-??')),
            ]),
            'powertrain' => $this->faker->randomElement(array_keys($powertrains)),
            'country_registration' => $this->faker->randomElement(array_keys($countries)),
            'status' => $this->faker->randomElement(collect(VehicleStatus::cases())->pluck('value')->toArray()),
            'fuel_types' => $this->faker->randomElements(
                array: array_keys($fuelTypes),
                count: $this->faker->numberBetween(1, 3),
                allowDuplicates: false,
            ),
            'tank_capacity' => $this->faker->numberBetween(0, 1000),
            'specifications' => collect(range(1, 5))->map(function () {
                return [
                    'name' => $this->faker->word(),
                    'value' => $this->faker->randomElement([
                        $this->faker->word(),
                        $this->faker->numberBetween(0, 9999),
                        $this->faker->boolean(),
                    ]),
                    'icon' => $this->faker->randomElement([
                        'ic:round-directions-car-filled',
                        'ic:baseline-star',
                        'mdi:engine',
                        'ic:baseline-local-gas-station',
                        'ic:baseline-route',
                        'ic:round-calendar-month',
                        'fa7-solid:industry',
                        'ic:round-local-offer',
                    ]),
                ];
            })->toArray(),
            'notifications' => $notifications,
        ];
    }
}
