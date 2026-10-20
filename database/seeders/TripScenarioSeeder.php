<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\PlanTrip;
use App\Models\EnvironmentalSticker;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\Refueling;
use App\Models\Tax;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vignette;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Vehicles with realistic records and trips for the trip planner, each for a
 * scenario: a trip without any warnings, papers that expire on the way, a
 * vignette and stickers that are missing, winter tires, an electric vehicle,
 * a stay along the way, an arrival time, toll roads and a night in a hotel.
 *
 * php artisan db:seed --class=TripScenarioSeeder
 *
 * For the first user, which is the one to log in with locally. Running it again
 * replaces the vehicles and trips it made before. The trips are planned by
 * the queue: php artisan queue:work.
 */
class TripScenarioSeeder extends Seeder
{
    /** Spijkenisse, where every trip starts. */
    private const HOME = ['label' => 'Spijkenisse, Zuid-Holland', 'latitude' => 51.8450, 'longitude' => 4.3290];

    private const PLACES = [
        'mayrhofen' => ['label' => 'Mayrhofen, Tirol', 'latitude' => 47.1667, 'longitude' => 11.8667],
        'paris' => ['label' => 'Paris, Île-de-France', 'latitude' => 48.8584, 'longitude' => 2.2945],
        'utrecht' => ['label' => 'Utrecht, Utrecht', 'latitude' => 52.0907, 'longitude' => 5.1214],
        'hamburg' => ['label' => 'Hamburg', 'latitude' => 53.5511, 'longitude' => 9.9937],
        'munich' => ['label' => 'München, Bayern', 'latitude' => 48.1351, 'longitude' => 11.5820],
        'siena' => ['label' => 'Siena, Toscana', 'latitude' => 43.3188, 'longitude' => 11.3308],
        'barcelona' => ['label' => 'Barcelona, Catalunya', 'latitude' => 41.3874, 'longitude' => 2.1686],
        'lyon' => ['label' => 'Lyon, Auvergne-Rhône-Alpes', 'latitude' => 45.7640, 'longitude' => 4.8357],
        'brussels' => ['label' => 'Bruxelles, Région de Bruxelles-Capitale', 'latitude' => 50.8467, 'longitude' => 4.3525],
        'krakow' => ['label' => 'Kraków, Małopolskie', 'latitude' => 50.0647, 'longitude' => 19.9450],
    ];

    /** The license plates of the vehicles this seeder makes, to replace them on the next run. */
    private const PLATES = ['K-482-NB', 'ZT-219-P', 'R-735-XJ', 'H-061-LS'];

    private CarbonImmutable $today;

    public function run(): void
    {
        $this->today = CarbonImmutable::today();
        $user = User::query()->orderBy('id')->firstOrFail();

        $trips = DB::transaction(function () use ($user) {
            $this->forget($user);

            return [
                ...$this->golf($user),
                ...$this->octavia($user),
                ...$this->tesla($user),
                ...$this->peugeot($user),
            ];
        });

        foreach ($trips as $trip) {
            PlanTrip::dispatch($trip);
        }

        $this->command->info(count($trips).' trips for '.$user->email.': run php artisan queue:work to plan them.');
    }

    /**
     * Everything in order: valid papers, vignettes and stickers, all season tires.
     *
     * @return list<Trip>
     */
    private function golf(User $user): array
    {
        $vehicle = $this->vehicle($user, [
            'brand' => 'Volkswagen', 'model' => 'Golf', 'version' => '1.5 eTSI Life', 'engine' => '1.5 eTSI 150 pk',
            'license_plate' => 'K-482-NB', 'powertrain' => 'gasoline', 'fuel_types' => ['Unleaded 95 (E10)', 'Super Plus (E5)'],
            'tank_capacity' => 50, 'construction_date' => '2021-03-12', 'purchase_date' => '2021-04-02', 'purchase_price' => 32450,
            'mileage_start' => 12, 'maintenance_interval_distance' => 30000, 'maintenance_interval_time' => 24,
        ]);

        $mileage = $this->refuelings($vehicle, 18, 61200, [5.6, 6.6], [32, 46], 'Unleaded 95 (E10)', [1.87, 2.05], 'all_season', [
            ['netherlands', 'Tango Spijkenisse'], ['netherlands', 'Shell Hoogvliet'], ['netherlands', 'TotalEnergies Botlek A15'],
            ['germany', 'Aral Emmerich A3'], ['belgium', 'Q8 Antwerpen-Noord'],
        ]);
        $this->maintenance($vehicle, $this->today->subMonths(4), $mileage - 6300, 'maintenance', apk: true, garage: 'Autobedrijf Van der Linden');
        $this->papers($vehicle, insuranceEnds: $this->today->addYear(), taxEnds: $this->today->addMonths(3)->endOfQuarter());
        $winter = $this->winterDay(19)->setTime(5, 0);
        $this->vignette($vehicle, 'austria', $winter->subDay(), $winter->addDays(10), 9.60, 'Digitale 10-dagen vignet');
        $this->sticker($vehicle, 'germany', null, 'Umweltplakette groen (4)');
        $this->sticker($vehicle, 'france', null, "Crit'Air 1");

        return [
            $this->trip($user, $vehicle, 'Wintersport Mayrhofen', 'departure', $winter, 85, [self::HOME, self::PLACES['mayrhofen']]),
            $this->trip($user, $vehicle, 'Weekend Parijs', 'arrival', $this->nextWeekday(CarbonImmutable::FRIDAY)->setTime(18, 30), 30, [self::HOME, self::PLACES['paris']]),
            $this->trip($user, $vehicle, 'Klant in Hamburg, via kantoor Utrecht', 'departure', $this->today->addDays(2)->setTime(7, 30), 60, [
                self::HOME, [...self::PLACES['utrecht'], 'stay_minutes' => 90], self::PLACES['hamburg'],
            ]),
        ];
    }

    /**
     * An older diesel with everything that can be wrong: the MOT and the
     * insurance expire on the way, maintenance is due, no vignettes or
     * stickers and summer tires in November.
     *
     * @return list<Trip>
     */
    private function octavia(User $user): array
    {
        $vehicle = $this->vehicle($user, [
            'brand' => 'Skoda', 'model' => 'Octavia Combi', 'version' => '2.0 TDI Ambition', 'engine' => '2.0 TDI 150 pk',
            'license_plate' => 'ZT-219-P', 'powertrain' => 'diesel', 'fuel_types' => ['Diesel'],
            'tank_capacity' => 55, 'construction_date' => '2016-09-01', 'purchase_date' => '2019-05-17', 'purchase_price' => 14900,
            'mileage_start' => 84210, 'maintenance_interval_distance' => 30000, 'maintenance_interval_time' => 12,
        ]);

        $mileage = $this->refuelings($vehicle, 20, 228400, [4.9, 5.8], [38, 52], 'Diesel', [1.69, 1.86], 'summer', [
            ['netherlands', 'Tango Spijkenisse'], ['netherlands', 'Tinq Rotterdam Charlois'], ['germany', 'Raiffeisen Kleve'],
            ['luxembourg', 'Aral Wasserbillig'], ['belgium', 'Gabriëls Hasselt'],
        ]);
        // The MOT of nearly a year ago expires in ten days; 600 km left until maintenance.
        $this->maintenance($vehicle, $this->today->subYear()->addDays(10), $mileage - 21000, 'small_maintenance', apk: true, garage: 'APK Centrum Spijkenisse');
        $this->maintenance($vehicle, $this->today->subMonths(5), $mileage - 29400, 'big_maintenance', garage: 'Skoda Dealer Rotterdam');
        $this->papers($vehicle, insuranceEnds: $this->today->addDays(14), taxEnds: $this->today->addMonths(2)->endOfMonth());

        $november = $this->today->month === 11 ? $this->today->addDays(16) : $this->today->addDays(16)->max($this->today->setDate($this->today->year, 11, 7));

        return [
            $this->trip($user, $vehicle, 'Herfstvakantie Toscane', 'departure', $november->setTime(6, 0), 100, [
                self::HOME, [...self::PLACES['munich'], 'stay_minutes' => 600], self::PLACES['siena'],
            ]),
            $this->trip($user, $vehicle, 'Barcelona in één ruk', 'departure', $this->today->addDays(11)->setTime(4, 30), 95, [self::HOME, self::PLACES['barcelona']]),
        ];
    }

    /**
     * An electric vehicle: charging stops instead of refueling.
     *
     * @return list<Trip>
     */
    private function tesla(User $user): array
    {
        $vehicle = $this->vehicle($user, [
            'brand' => 'Tesla', 'model' => 'Model 3', 'version' => 'Long Range AWD', 'engine' => 'Dual Motor',
            'license_plate' => 'R-735-XJ', 'powertrain' => 'electricity', 'fuel_types' => ['Electricity DC', 'Electricity AC'],
            'tank_capacity' => 75, 'construction_date' => '2023-02-20', 'purchase_date' => '2023-03-15', 'purchase_price' => 49990,
            'mileage_start' => 5, 'maintenance_interval_distance' => 0, 'maintenance_interval_time' => 24,
        ]);

        $this->refuelings($vehicle, 16, 38900, [15.8, 18.4], [28, 58], 'Electricity DC', [0.39, 0.62], 'all_season', [
            ['netherlands', 'Tesla Supercharger Rotterdam'], ['netherlands', 'Fastned De Watering'], ['netherlands', 'Ionity Hazeldonk'],
            ['netherlands', 'Thuis laadpaal'], ['belgium', 'Allego Antwerpen'],
        ], electric: true);
        $this->papers($vehicle, insuranceEnds: $this->today->addMonths(10), taxEnds: $this->today->addYear());
        $this->sticker($vehicle, 'france', null, "Crit'Air E");

        return [
            $this->trip($user, $vehicle, 'Elektrisch naar Lyon', 'departure', $this->today->addDays(9)->setTime(6, 0), 90, [self::HOME, self::PLACES['lyon']]),
            $this->trip($user, $vehicle, 'Vergadering Brussel', 'arrival', $this->nextWeekday(CarbonImmutable::TUESDAY)->setTime(10, 0), 45, [self::HOME, self::PLACES['brussels']]),
        ];
    }

    /**
     * A small tank on a long trip to the east, over toll roads.
     *
     * @return list<Trip>
     */
    private function peugeot(User $user): array
    {
        $vehicle = $this->vehicle($user, [
            'brand' => 'Peugeot', 'model' => '208', 'version' => '1.2 PureTech Allure', 'engine' => '1.2 PureTech 100 pk',
            'license_plate' => 'H-061-LS', 'powertrain' => 'gasoline', 'fuel_types' => ['Unleaded 95 (E10)'],
            'tank_capacity' => 44, 'construction_date' => '2022-06-30', 'purchase_date' => '2022-07-22', 'purchase_price' => 23700,
            'mileage_start' => 8, 'maintenance_interval_distance' => 20000, 'maintenance_interval_time' => 12,
        ]);

        $mileage = $this->refuelings($vehicle, 14, 31800, [5.2, 6.1], [26, 38], 'Unleaded 95 (E10)', [1.85, 2.02], 'winter', [
            ['netherlands', 'Tango Spijkenisse'], ['netherlands', 'Esso Barendrecht'], ['germany', 'Jet Bocholt'],
        ]);
        $this->maintenance($vehicle, $this->today->subMonths(3), $mileage - 4100, 'maintenance', apk: false, garage: 'Peugeot Van Mossel Rotterdam');
        $this->papers($vehicle, insuranceEnds: $this->today->addMonths(8), taxEnds: $this->today->addMonths(5));

        return [
            $this->trip($user, $vehicle, 'Familiebezoek Kraków', 'departure', $this->today->addDays(25)->setTime(5, 0), 50, [self::HOME, self::PLACES['krakow']]),
        ];
    }

    /**
     * The vehicles and trips this seeder made before, with their records.
     */
    private function forget(User $user): void
    {
        $vehicles = Vehicle::query()->where('user_id', $user->id)->whereIn('license_plate', self::PLATES)->pluck('id');

        Trip::query()->whereIn('vehicle_id', $vehicles)->each(function (Trip $trip) {
            $trip->waypoints()->delete();
            $trip->delete();
        });

        foreach ([Refueling::class, Maintenance::class, Insurance::class, Tax::class, Vignette::class, EnvironmentalSticker::class] as $model) {
            $model::query()->whereIn('vehicle_id', $vehicles)->delete();
        }

        Vehicle::query()->whereIn('id', $vehicles)->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function vehicle(User $user, array $attributes): Vehicle
    {
        return Vehicle::query()->create([
            ...$attributes,
            'user_id' => $user->id,
            'brand' => array_search($attributes['brand'], config('vehicles.brands'), true),
            'country_registration' => 'netherlands',
            'status' => 'drivable',
            'mileage_latest' => $attributes['mileage_start'],
            'specifications' => [],
            'notifications' => config('default_notification_settings'),
        ]);
    }

    /**
     * Refuelings (or charging sessions) every ten to sixteen days up to a few
     * days ago, mostly on the motorway, at the gas stations given.
     *
     * @param  array{float, float}  $consumption  Liters, or kWh, per 100 km.
     * @param  array{int, int}  $amount  Liters, or kWh, per refueling.
     * @param  array{float, float}  $price  Per liter, or kWh.
     * @param  list<array{string, string}>  $stations  Country and name.
     * @return int The mileage now.
     */
    private function refuelings(Vehicle $vehicle, int $count, int $mileage, array $consumption, array $amount, string $fuelType, array $price, string $tires, array $stations, bool $electric = false): int
    {
        $dates = [];
        $date = $this->today->subDays(3);

        for ($index = 0; $index < $count; $index++) {
            $dates[] = $date;
            $date = $date->subDays(fake()->numberBetween(10, 16));
        }

        foreach (array_reverse($dates) as $date) {
            [$country, $station] = fake()->randomElement($stations);
            $home = $electric && str_starts_with($station, 'Thuis');
            $liters = fake()->randomFloat(2, $amount[0], $amount[1]);
            $perHundred = fake()->randomFloat(2, $consumption[0], $consumption[1]);
            $distance = (int) round($liters / $perHundred * 100);
            $unitPrice = $home ? fake()->randomFloat(3, 0.24, 0.31) : fake()->randomFloat(3, $price[0], $price[1]);

            Refueling::query()->create([
                'vehicle_id' => $vehicle->id,
                'date' => $date->toDateString(),
                'country' => $country,
                'gas_station' => $station,
                'fuel_type' => $home ? 'Electricity AC' : $fuelType,
                'amount' => $liters,
                'unit_price' => $unitPrice,
                'total_price' => round($liters * $unitPrice, 2),
                'mileage_begin' => $mileage,
                'mileage_end' => $mileage + $distance,
                'percentage' => $electric ? 80 : 100,
                'fuel_consumption_onboard_computer' => round($perHundred - fake()->randomFloat(1, 0.1, 0.4), 1),
                'fuel_consumption' => $perHundred,
                'costs_per_kilometer' => round($liters * $unitPrice / $distance, 2),
                'tires' => $tires,
                'climate_control' => ['automatically'],
                'routes' => fake()->randomElement([['motorway'], ['motorway', 'country_road'], ['motorway', 'city'], ['city', 'country_road']]),
                'driving_style' => fake()->randomElement(['average', 'average', 'slow']),
                'avg_speed' => fake()->numberBetween(52, 88),
                'payment_method' => fake()->randomElement(['bank_card', 'app', 'fuel_card']),
                'charge_time' => $electric ? sprintf('%02d:%02d', $home ? fake()->numberBetween(3, 7) : 0, fake()->numberBetween(18, 45)) : null,
                'service_by_attendant' => false,
            ]);

            $mileage += $distance;
        }

        $vehicle->update(['mileage_latest' => $mileage]);

        return $mileage;
    }

    private function maintenance(Vehicle $vehicle, CarbonImmutable $date, int $mileage, string $type, bool $apk = false, ?string $garage = null): void
    {
        Maintenance::query()->create([
            'vehicle_id' => $vehicle->id,
            'date' => $date->toDateString(),
            'garage' => $garage,
            'type_maintenance' => $type,
            'apk' => $apk,
            'apk_date' => $apk ? $date->addYear()->toDateString() : null,
            'airco_check' => false,
            'description' => null,
            'tasks' => array_filter([
                ['task' => 'Olie en oliefilter vervangen', 'price' => 129.50, 'icon' => 'mdi:oil'],
                ['task' => 'Luchtfilter vervangen', 'price' => 34.95, 'icon' => 'mdi:air-filter'],
                $apk ? ['task' => 'APK keuring', 'price' => 49.00, 'icon' => 'mdi:clipboard-check-outline'] : null,
            ]),
            'total_price' => $apk ? 213.45 : 164.45,
            'mileage' => $mileage,
            'payment_method' => 'bank_card',
        ]);
    }

    private function papers(Vehicle $vehicle, CarbonImmutable $insuranceEnds, CarbonImmutable $taxEnds): void
    {
        Insurance::query()->create([
            'vehicle_id' => $vehicle->id,
            'insurance_company' => fake()->randomElement(['Centraal Beheer', 'ANWB', 'Univé', 'InShared']),
            'start_date' => $insuranceEnds->subYear()->addDay()->toDateString(),
            'end_date' => $insuranceEnds->toDateString(),
            'type' => 'wa+',
            'price' => fake()->randomFloat(2, 38, 72),
            'invoice_day' => 1,
        ]);

        Tax::query()->create([
            'vehicle_id' => $vehicle->id,
            'start_date' => $taxEnds->subMonths(3)->addDay()->toDateString(),
            'end_date' => $taxEnds->toDateString(),
            'price' => fake()->randomFloat(2, 0, 290),
            'invoice_day' => 15,
        ]);
    }

    private function vignette(Vehicle $vehicle, string $country, CarbonImmutable $start, CarbonImmutable $end, float $price, string $comments): void
    {
        Vignette::query()->create([
            'vehicle_id' => $vehicle->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'price' => $price,
            'country' => $country,
            'areas' => null,
            'comments' => $comments,
        ]);
    }

    private function sticker(Vehicle $vehicle, string $country, ?CarbonImmutable $end, string $comments): void
    {
        EnvironmentalSticker::query()->create([
            'vehicle_id' => $vehicle->id,
            'start_date' => $this->today->subYears(2)->toDateString(),
            'end_date' => $end?->toDateString(),
            'price' => $country === 'france' ? 3.77 : 6.00,
            'country' => $country,
            'areas' => null,
            'comments' => $comments,
        ]);
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float, stay_minutes?: int}>  $waypoints
     */
    private function trip(User $user, Vehicle $vehicle, string $name, string $mode, CarbonImmutable $at, int $fuelLevel, array $waypoints): Trip
    {
        $trip = Trip::query()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'name' => $name,
            'time_mode' => $mode,
            // The time is local, as the planner form gives it.
            'time_at' => CarbonImmutable::parse($at->format('Y-m-d H:i'), config('trip_planner.timezone'))->utc(),
            'fuel_level' => $fuelLevel,
            'status' => Trip::STATUS_PLANNING,
        ]);

        $last = count($waypoints) - 1;

        foreach ($waypoints as $position => $waypoint) {
            $trip->waypoints()->create([
                'position' => $position,
                'label' => $waypoint['label'],
                'latitude' => $waypoint['latitude'],
                'longitude' => $waypoint['longitude'],
                'stay_minutes' => $position > 0 && $position < $last ? ($waypoint['stay_minutes'] ?? 0) : 0,
            ]);
        }

        return $trip;
    }

    /**
     * A day in the coming December, or in this one when it is winter already.
     */
    private function winterDay(int $day): CarbonImmutable
    {
        $date = $this->today->setDate($this->today->year, 12, $day);

        return $date->isPast() ? $this->today->addDays(7) : $date;
    }

    private function nextWeekday(int $weekday): CarbonImmutable
    {
        return $this->today->next($weekday);
    }
}
