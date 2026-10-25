<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Toll;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Gives every existing vehicle toll passages on real toll roads, as a driver
 * in the Netherlands makes them, so the toll on the dashboard and in the trip
 * planner has something to show:
 * php artisan db:seed --class=TollSeeder
 *
 * The toll of each vehicle is replaced: what was there is deleted (softly)
 * first. The prices are close to those of 2025 and 2026, not exact.
 */
class TollSeeder extends Seeder
{
    /** The first day of passages: the Blankenburgverbinding opened at the end of 2024. */
    private const SINCE = '2025-01-01';

    /** The sections, with their price by year. */
    private const SECTIONS = [
        'blankenburg' => [
            'country' => 'netherlands', 'road_type' => 'highway', 'road' => ['A24'], 'type' => 'location',
            'start_location' => 'Blankenburgverbinding', 'end_location' => null, 'toll_company' => 'Rijkswaterstaat',
            'payment_circumstances' => 'camera', 'prices' => [2025 => 1.51, 2026 => 1.57],
        ],
        'kiltunnel' => [
            'country' => 'netherlands', 'road_type' => 'provincial', 'road' => ['N217'], 'type' => 'location',
            'start_location' => 'Kiltunnel', 'end_location' => null, 'toll_company' => 'Kiltunnel Dordrecht',
            'payment_circumstances' => 'toll_gate', 'payment_method' => 'bank_card', 'prices' => [2025 => 2.75, 2026 => 2.85],
        ],
        'liefkenshoek' => [
            'country' => 'belgium', 'road_type' => 'ring', 'road' => ['R2'], 'type' => 'location',
            'start_location' => 'Liefkenshoektunnel', 'end_location' => null, 'toll_company' => 'Tunnel Liefkenshoek',
            'payment_circumstances' => 'toll_gate', 'payment_method' => 'bank_card', 'prices' => [2025 => 6.90, 2026 => 7.10],
        ],
        'a26' => [
            'country' => 'france', 'road_type' => 'highway', 'road' => ['A26'], 'type' => 'section',
            'start_location' => 'Nordausques', 'end_location' => 'Reims', 'toll_company' => 'Sanef',
            'payment_circumstances' => 'toll_gate', 'payment_method' => 'toll_badge', 'prices' => [2025 => 23.90, 2026 => 24.40],
        ],
        'a6' => [
            'country' => 'france', 'road_type' => 'highway', 'road' => ['A6'], 'type' => 'section',
            'start_location' => 'Beaune', 'end_location' => 'Villefranche-sur-Saône', 'toll_company' => 'APRR',
            'payment_circumstances' => 'toll_gate', 'payment_method' => 'toll_badge', 'prices' => [2025 => 14.40, 2026 => 14.70],
        ],
        'brenner' => [
            'country' => 'austria', 'road_type' => 'highway', 'road' => ['A13'], 'type' => 'section',
            'start_location' => 'Schönberg', 'end_location' => 'Brenner', 'toll_company' => 'ASFINAG',
            'payment_circumstances' => 'toll_gate', 'payment_method' => 'bank_card', 'prices' => [2025 => 11.50, 2026 => 12.00],
        ],
        'a22' => [
            'country' => 'italy', 'road_type' => 'highway', 'road' => ['A22'], 'type' => 'section',
            'start_location' => 'Brennero', 'end_location' => 'Verona Nord', 'toll_company' => 'Autostrada del Brennero',
            'payment_circumstances' => 'toll_gate', 'payment_method' => 'bank_card', 'prices' => [2025 => 23.30, 2026 => 23.80],
        ],
    ];

    /** The fee on top of a camera toll that was not paid in time. */
    private const REMINDER_FEE = 9.00;

    public function run(): void
    {
        Vehicle::query()->orderBy('created_at')->get()->each(fn (Vehicle $vehicle, int $index) => $this->seedVehicle($vehicle, $index));

        // The dashboard caches its statistics for a while.
        Cache::flush();
    }

    private function seedVehicle(Vehicle $vehicle, int $index): void
    {
        Toll::query()->where('vehicle_id', $vehicle->id)->delete();

        $since = CarbonImmutable::parse(self::SINCE);
        $since = $vehicle->purchase_date ? $since->max(CarbonImmutable::parse($vehicle->purchase_date)) : $since;
        $today = CarbonImmutable::today();
        // From February 2026 one in three drives over the Blankenburgverbinding three days a week,
        // one in three once a week; the others now and then, as everyone did before.
        $commute = $index % 3;
        // Half pay the camera toll online by hand, the others with an app that pays automatically.
        $method = $index % 2 === 0 ? 'online' : 'app';

        for ($day = $since; $day->lte($today); $day = $day->addDay()) {
            $often = $day->gte('2026-02-01') && $commute < 2;
            $drives = $often
                ? in_array($day->dayOfWeekIso, $commute === 0 ? [1, 3, 5] : [3], true) && fake()->boolean(90)
                : fake()->boolean(7);

            if ($drives) {
                // There and back again, mostly.
                for ($passages = fake()->boolean(80) ? 2 : 1; $passages > 0; $passages--) {
                    $this->passage($vehicle, 'blankenburg', $day, ['payment_method' => $method]);
                }
            }

            if (fake()->boolean(2)) {
                $this->passage($vehicle, 'kiltunnel', $day);
                $this->passage($vehicle, 'kiltunnel', $day);
            }
        }

        // Once paid too late: the reminder came with a fee.
        if ($commute === 0 && $method === 'online') {
            $late = Toll::query()->where('vehicle_id', $vehicle->id)->where('start_location', 'Blankenburgverbinding')
                ->whereDate('start_date', '>=', $today->subDays(45))->inRandomOrder()->first();
            $late?->update(['price' => round((float) $late->price + self::REMINDER_FEE, 2)]);
        }

        // On holiday: to Italy over the Brenner in the summer of 2025, to the south of France in 2026.
        if ($index % 2 === 0) {
            $this->holiday($vehicle, $since, CarbonImmutable::parse('2025-07-12'), ['liefkenshoek', 'brenner', 'a22'], 14);
        }

        $this->holiday($vehicle, $since, CarbonImmutable::parse('2026-08-01')->addDays($index % 4), ['liefkenshoek', 'a26', 'a6'], 15);
    }

    /**
     * The passages of a holiday: on the way there in the order given, on the
     * way back in the other order, a day per country further.
     *
     * @param  list<string>  $sections
     */
    private function holiday(Vehicle $vehicle, CarbonImmutable $since, CarbonImmutable $departure, array $sections, int $days): void
    {
        $back = $departure->addDays($days);

        if ($departure->lt($since) || $back->gt(CarbonImmutable::today())) {
            return;
        }

        foreach ($sections as $n => $section) {
            $this->passage($vehicle, $section, $departure->addDays(intdiv($n, 2)));
        }

        foreach (array_reverse($sections) as $n => $section) {
            $this->passage($vehicle, $section, $back->addDays(intdiv($n, 2)), self::SECTIONS[$section]['type'] === 'section' ? [
                'start_location' => self::SECTIONS[$section]['end_location'],
                'end_location' => self::SECTIONS[$section]['start_location'],
            ] : []);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function passage(Vehicle $vehicle, string $section, CarbonImmutable $day, array $attributes = []): void
    {
        $config = self::SECTIONS[$section];

        Toll::factory()->for($vehicle)->create([
            ...collect($config)->except('prices')->all(),
            'payment_method' => $config['payment_method'] ?? null,
            'price' => $config['prices'][$day->year] ?? end($config['prices']),
            'start_date' => $day->toDateString(),
            'end_date' => null,
            ...$attributes,
        ]);
    }
}
