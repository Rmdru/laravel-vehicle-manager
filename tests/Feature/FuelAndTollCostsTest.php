<?php

namespace Tests\Feature;

use App\Models\FuelPrice;
use App\Models\Refueling;
use App\Models\Toll;
use App\Models\Vehicle;
use App\Services\DashboardService;
use App\Services\FuelCostInsights;
use App\Services\TollSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FuelAndTollCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-25 12:00:00');
        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * A refueling of a month of driving, with nothing recorded of the circumstances.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function refueling(Vehicle $vehicle, array $attributes): Refueling
    {
        return Refueling::factory()->for($vehicle)->create([
            'country' => 'netherlands',
            'fuel_type' => 'Unleaded 95 (E10)',
            'gas_station' => 'Shell A2',
            'tires' => null,
            'climate_control' => null,
            'routes' => null,
            'driving_style' => null,
            ...$attributes,
        ]);
    }

    /**
     * The amount of a formatted sum of money, such as "+€ 1.234,56".
     */
    private function amount(string $money): float
    {
        $number = (float) str_replace(['.', ','], ['', '.'], (string) preg_replace('/[^0-9,.]/', '', $money));

        return str_starts_with($money, '−') ? -$number : $number;
    }

    public function test_the_change_of_the_fuel_costs_is_split_into_price_distance_and_consumption()
    {
        $vehicle = $this->signInWithVehicle();
        $mileage = 10000;

        // A refueling every month: a year ago 1000 km on 6 l/100 km at € 1,80, now 1200 km on 6,5 l/100 km at € 2,10.
        for ($month = 0; $month <= 15; $month++) {
            $now = $month >= 13;
            $km = $now ? 1200 : 1000;
            $this->refueling($vehicle, [
                'date' => now()->setDate(2025, 7, 20)->addMonthsNoOverflow($month)->toDateString(),
                'mileage_begin' => $mileage,
                'mileage_end' => $mileage += $km,
                'fuel_consumption' => $now ? 6.5 : 6.0,
                'unit_price' => $now ? 2.10 : 1.80,
            ]);
        }

        $change = app(FuelCostInsights::class)->change($vehicle);

        $this->assertTrue($change['sameSeason']);
        $this->assertSame('21 Jul 2026 – 20 Oct 2026', $change['period']);
        $this->assertSame('21 Jul 2025 – 20 Oct 2025', $change['compared']);
        $this->assertSame(1, $change['trend']);
        $this->assertSame(['price', 'distance', 'consumption'], array_column($change['parts'], 'key'));

        // Each part went up, and together they make up the difference.
        $difference = $this->amount($change['now']) - $this->amount($change['before']);
        $this->assertSame([1, 1, 1], array_column($change['parts'], 'trend'));
        $this->assertEqualsWithDelta($difference, array_sum(array_column($change['parts'], 'value')), 0.02);
        $this->assertEqualsWithDelta($difference, $this->amount($change['difference']), 0.01);
        $this->assertEqualsWithDelta($difference * 12, $this->amount($change['perYear']), 0.1);
        $this->assertSame('€ 1,800 → € 2,100/l', $change['parts'][0]['detail']);
        $this->assertSame('993 → 1.191 km per month', $change['parts'][1]['detail']);
        $this->assertSame('6,0 → 6,5 l/100km', $change['parts'][2]['detail']);
    }

    public function test_the_change_of_the_fuel_costs_needs_refuelings_in_both_periods()
    {
        $vehicle = $this->signInWithVehicle();
        $this->refueling($vehicle, ['date' => '2026-10-01', 'mileage_begin' => 1000, 'mileage_end' => 1500, 'fuel_consumption' => 6]);
        $this->refueling($vehicle, ['date' => '2026-10-20', 'mileage_begin' => 1500, 'mileage_end' => 2000, 'fuel_consumption' => 6]);

        $this->assertNull(app(FuelCostInsights::class)->change($vehicle));
    }

    public function test_what_the_cheapest_usual_gas_station_would_have_saved()
    {
        $vehicle = $this->signInWithVehicle();

        // The average price of the country moves; the gas stations stay as far from it.
        foreach (['2026-06-01' => 1.90, '2026-08-01' => 2.10] as $date => $average) {
            FuelPrice::create(['date' => $date, 'country' => 'netherlands', 'fuel_type' => 'Unleaded 95 (E10)', 'price' => $average]);

            $this->refueling($vehicle, ['date' => $date, 'gas_station' => 'Tango Utrecht', 'amount' => 40, 'unit_price' => $average - 0.10]);
            $this->refueling($vehicle, ['date' => $date, 'gas_station' => 'Shell A2', 'amount' => 40, 'unit_price' => $average + 0.05]);
        }

        // Abroad, or before the past year: left out.
        $this->refueling($vehicle, ['date' => '2026-08-01', 'country' => 'germany', 'gas_station' => 'Aral', 'amount' => 40, 'unit_price' => 2.50]);
        $this->refueling($vehicle, ['date' => '2025-06-01', 'gas_station' => 'Shell A2', 'amount' => 40, 'unit_price' => 2.50]);

        $savings = app(FuelCostInsights::class)->savings($vehicle);
        $fuelType = $savings['fuelTypes'][0];

        $this->assertSame('Tango Utrecht', $fuelType['best']);
        // 80 l at € 0,15 a liter more than at the cheapest.
        $this->assertSame('€ 12,00', $fuelType['savings']);
        $this->assertSame([
            ['name' => 'Tango Utrecht', 'visits' => 2, 'best' => true, 'difference' => '€ 0,100/l below average', 'trend' => -1, 'extra' => null],
            ['name' => 'Shell A2', 'visits' => 2, 'best' => false, 'difference' => '€ 0,050/l above average', 'trend' => 1, 'extra' => '€ 12,00'],
        ], $fuelType['stations']);
    }

    public function test_no_savings_without_average_prices_to_compare_with()
    {
        $vehicle = $this->signInWithVehicle();
        $this->refueling($vehicle, ['date' => '2026-08-01', 'gas_station' => 'Tango', 'unit_price' => 2]);
        $this->refueling($vehicle, ['date' => '2026-09-01', 'gas_station' => 'Shell', 'unit_price' => 2.1]);

        $this->assertNull(app(FuelCostInsights::class)->savings($vehicle));
    }

    public function test_the_consumption_by_circumstance_with_what_it_costs()
    {
        $vehicle = $this->signInWithVehicle();
        $mileage = 10000;

        // Fast on the motorway uses more than slow; the last refueling was at € 2,00.
        foreach ([['slow', 5.0], ['slow', 5.0], ['fast', 7.0], ['fast', 7.0], ['average', 9.0]] as $i => [$style, $consumption]) {
            $this->refueling($vehicle, [
                'date' => now()->subWeeks(10 - $i)->toDateString(),
                'mileage_begin' => $mileage,
                'mileage_end' => $mileage += 500,
                'fuel_consumption' => $consumption,
                'unit_price' => 2.0,
                'driving_style' => $style,
                'routes' => $style === 'fast' ? ['motorway'] : ['motorway', 'city'],
            ]);
        }

        $circumstances = app(FuelCostInsights::class)->circumstances($vehicle);

        // Of 2500 km at 6.6 l/100 km on average.
        $this->assertSame('6,6 l/100km', $circumstances['average']);
        $this->assertSame('€ 2,000/l', $circumstances['price']);

        // A single refueling says too little: no "average" style. Every route has more than one.
        $style = $circumstances['circumstances'][0];
        $this->assertSame('driving_style', $style['key']);
        $this->assertSame(['slow', 'fast'], array_column($style['values'], 'key'));
        $this->assertSame(['5,0 l/100km', '7,0 l/100km'], array_column($style['values'], 'consumption'));
        $this->assertSame(['−24%', '+6%'], array_column($style['values'], 'difference'));
        // 1.6 l less and 0.4 l more per 100 km, at € 2,00 a liter.
        $this->assertSame(['−€ 32,00', '+€ 8,00'], array_column($style['values'], 'costs'));

        $routes = $circumstances['circumstances'][1];
        $this->assertSame('routes', $routes['key']);
        // The most economical first.
        $this->assertSame(['city', 'motorway'], array_column($routes['values'], 'key'));
        $this->assertSame([3, 5], array_column($routes['values'], 'refuelings'));
        $this->assertCount(2, $circumstances['circumstances']);
    }

    public function test_the_toll_by_section_with_the_pace_of_the_last_months()
    {
        $vehicle = $this->signInWithVehicle();
        $section = ['country' => 'netherlands', 'road_type' => 'highway', 'road' => ['A24'], 'type' => 'location', 'start_location' => 'Tunnel', 'end_location' => null, 'toll_company' => null];

        // Twice a week in the last three months.
        for ($day = 0; $day < 90; $day += 3.5) {
            Toll::factory()->for($vehicle)->create([...$section, 'start_date' => today()->subDays((int) $day)->toDateString(), 'price' => 1.57]);
        }

        // The same section, typed a little differently, and another one.
        Toll::factory()->for($vehicle)->create([...$section, 'start_location' => 'tunnel ', 'road' => ['24'], 'start_date' => '2026-01-10', 'price' => 1.51]);
        Toll::factory()->for($vehicle)->create([...$section, 'country' => 'france', 'road' => ['A1'], 'start_location' => 'Paris', 'start_date' => '2026-06-01', 'price' => 20]);

        $overview = app(TollSections::class)->overview($vehicle);

        $this->assertCount(2, $overview);
        $this->assertSame('Tunnel', $overview[0]['label']);
        $this->assertSame('NL', $overview[0]['isoCode']);
        $this->assertSame('A24', $overview[0]['roads'][0]['label']);
        $this->assertSame(27, $overview[0]['passages']);
        $this->assertSame('€ 1,57', $overview[0]['usual']);
        $this->assertSame('8,8', $overview[0]['perMonth']);
        // 26 passages in 90 days: 8,8 a month.
        $this->assertSame('€ 165,67', $overview[0]['perYear']);
        $this->assertSame('Paris', $overview[1]['label']);
        $this->assertNull($overview[1]['perYear']);

        // In a period, only the passages of the period count.
        $this->assertSame(1, app(TollSections::class)->overview($vehicle, '2026-01-01', '2026-01-31')[0]['passages']);
    }

    public function test_a_section_driven_both_ways_on_a_single_trip_is_one_section_without_a_pace()
    {
        $vehicle = $this->signInWithVehicle();
        $section = ['country' => 'france', 'road_type' => 'highway', 'road' => ['A26'], 'type' => 'section', 'price' => 24.40];

        Toll::factory()->for($vehicle)->create([...$section, 'start_location' => 'Nordausques', 'end_location' => 'Reims', 'start_date' => '2026-08-01']);
        Toll::factory()->for($vehicle)->create([...$section, 'start_location' => 'Reims', 'end_location' => 'Nordausques', 'start_date' => '2026-08-15']);

        $overview = app(TollSections::class)->overview($vehicle);

        $this->assertCount(1, $overview);
        // As it was driven the last time.
        $this->assertSame('Reims – Nordausques', $overview[0]['label']);
        $this->assertSame(2, $overview[0]['passages']);
        // A holiday says nothing about what a year comes to.
        $this->assertNull($overview[0]['perYear']);
    }

    public function test_a_toll_that_cost_more_than_usual_makes_a_notification()
    {
        $vehicle = $this->signInWithVehicle();
        $section = ['country' => 'netherlands', 'road_type' => 'highway', 'road' => ['A24'], 'type' => 'location', 'start_location' => 'Tunnel', 'end_location' => null, 'payment_circumstances' => 'camera', 'payment_method' => 'app'];

        foreach (['2026-09-01' => 1.57, '2026-09-08' => 1.57, '2026-09-15' => 10.57, '2026-09-22' => 1.57] as $date => $price) {
            Toll::factory()->for($vehicle)->create([...$section, 'start_date' => $date, 'price' => $price]);
        }

        $notifications = collect(app(DashboardService::class)->notifications($vehicle))->filter(fn (array $notification) => str_starts_with($notification['key'], 'toll_'));

        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('Tunnel on Sep 15, 2026 cost € 10,57, € 9,00 more than usual', $notifications->first()['text']);
    }

    public function test_a_camera_toll_paid_online_again_and_again_makes_a_notification()
    {
        $vehicle = $this->signInWithVehicle();
        $section = ['country' => 'netherlands', 'road_type' => 'highway', 'road' => ['A24'], 'type' => 'location', 'start_location' => 'Tunnel', 'end_location' => null, 'payment_circumstances' => 'camera', 'payment_method' => 'online', 'price' => 1.57];

        foreach (['2026-09-01', '2026-09-08'] as $date) {
            Toll::factory()->for($vehicle)->create([...$section, 'start_date' => $date]);
        }

        $keys = fn () => collect(app(TollSections::class)->notifications($vehicle))->pluck('key')->all();
        $this->assertSame([], $keys());

        Toll::factory()->for($vehicle)->create([...$section, 'start_date' => '2026-09-15']);

        $this->assertCount(1, $keys());
        $this->assertStringStartsWith('toll_manual:', $keys()[0]);
    }

    public function test_the_dashboard_shows_the_fuel_and_toll_costs()
    {
        $vehicle = $this->signInWithVehicle();
        Toll::factory()->for($vehicle)->create(['start_date' => '2026-10-01', 'price' => 1.57]);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->loadDeferredProps('fuel', fn (Assert $page) => $page
                    ->where('fuelCostChange', null)
                    ->where('fuelSavings', null)
                    ->where('consumptionByCircumstance', null))
                ->loadDeferredProps('costs', fn (Assert $page) => $page
                    ->has('tollSections', 1)));
    }
}
