<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Reconditioning;
use App\Models\Vehicle;
use App\Services\WashingCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WashingCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-25 12:00:00');
    }

    private function wash(Vehicle $vehicle, string $date, string $type, string $executor, float $price = 0): void
    {
        Reconditioning::factory()->for($vehicle)->create(['date' => $date, 'type' => $type, 'executor' => $executor, 'price' => $price]);
    }

    /**
     * Washed by the owner in spring and summer with products bought at once,
     * at the carwash in the winter, and once inside.
     */
    private function vehicle(): Vehicle
    {
        $vehicle = $this->signInWithVehicle();

        Product::factory()->for($vehicle)->create(['category' => ProductCategory::Cleaning, 'price' => 80, 'date' => '2026-04-01']);
        // Not for washing.
        Product::factory()->for($vehicle)->create(['category' => ProductCategory::EngineOil, 'price' => 30, 'date' => '2026-04-01']);

        foreach (['2026-04-05', '2026-05-05', '2026-06-05', '2026-07-05'] as $date) {
            $this->wash($vehicle, $date, 'exterior_cleaning', 'myself');
        }

        $this->wash($vehicle, '2026-01-10', 'carwash', 'company', 15);
        $this->wash($vehicle, '2026-02-10', 'carwash', 'company', 15);
        $this->wash($vehicle, '2026-02-20', 'carwash', 'company', 12);
        $this->wash($vehicle, '2026-05-10', 'interior_cleaning', 'myself');

        return $vehicle;
    }

    public function test_washing_yourself_divides_the_products_over_the_washes()
    {
        $overview = app(WashingCosts::class)->overview($this->vehicle());
        $kinds = collect($overview['kinds'])->keyBy('key');

        // Without a period of all time: from January, the first wash, to this month.
        $this->assertSame(10, $overview['months']);
        $this->assertSame(4, $kinds['self']['washes']);
        $this->assertSame('€ 20,00', $kinds['self']['perWash']);
        $this->assertSame('€ 8,00', $kinds['self']['perMonth']);
        $this->assertSame(3, $kinds['carwash']['washes']);
        $this->assertSame('€ 14,00', $kinds['carwash']['perWash']);
        $this->assertSame('€ 4,20', $kinds['carwash']['perMonth']);
        $this->assertArrayNotHasKey('others', $kinds->all());

        $this->assertSame(7, $overview['combined']['washes']);
        $this->assertSame('€ 12,20', $overview['combined']['perMonth']);
        $this->assertSame('€ 17,43', $overview['combined']['perWash']);
    }

    public function test_the_period_filter_applies()
    {
        $overview = app(WashingCosts::class)->overview($this->vehicle(), '2026-01-01', '2026-03-31');
        $kinds = collect($overview['kinds'])->keyBy('key');

        $this->assertSame(3, $overview['months']);
        $this->assertSame(['carwash'], $kinds->keys()->all());
        $this->assertSame('€ 14,00', $kinds['carwash']['perMonth']);
        $this->assertNull($overview['insight']);
    }

    public function test_nothing_washed_shows_nothing()
    {
        $this->assertNull(app(WashingCosts::class)->overview($this->signInWithVehicle()));
    }

    public function test_the_dashboard_shows_the_washing_costs()
    {
        $this->vehicle();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('costs', fn (Assert $page) => $page->where('washingCosts.combined.washes', 7)));
    }
}
