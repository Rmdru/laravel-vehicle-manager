<?php

namespace Tests\Unit;

use App\Models\Ferry;
use App\Models\Insurance;
use App\Models\Tax;
use App\Support\Roads;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainTest extends TestCase
{
    public function test_a_ferry_casts_its_dates_to_datetimes()
    {
        $ferry = new Ferry([
            'start_date' => '2026-06-15 08:30:00',
            'end_date' => '2026-06-15 14:45:00',
        ]);

        $this->assertInstanceOf(CarbonInterface::class, $ferry->start_date);
        $this->assertSame('2026-06-15 08:30:00', $ferry->start_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 14:45:00', $ferry->end_date->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function monthlyCosts(): array
    {
        return ['insurance' => [Insurance::class], 'tax' => [Tax::class]];
    }

    #[DataProvider('monthlyCosts')]
    public function test_the_newer_period_wins_when_two_overlap_in_a_month(string $model)
    {
        $older = new $model;
        $older->id = 'older';
        $older->start_date = '2025-01-01';
        $older->end_date = '2026-07-11';

        $newer = new $model;
        $newer->id = 'newer';
        $newer->start_date = '2026-07-11';
        $newer->end_date = null;

        $active = $model::getActiveMonthlyRecordForMonth(collect([$older, $newer]), Carbon::create(2026, 7, 1));

        $this->assertSame('newer', $active?->id);
    }

    public function test_road_badges_use_the_prefix_and_colors_of_the_country()
    {
        $this->assertSame(
            [['label' => 'A2', 'classes' => 'bg-red-500 text-white'], ['label' => 'A27', 'classes' => 'bg-red-500 text-white']],
            Roads::badges('netherlands', 'highway', ['2', 'E27']),
        );

        $this->assertSame('X99', Roads::badges('netherlands', 'unknown', 'X99')[0]['label']);
        $this->assertSame([], Roads::badges('netherlands', 'highway', null));
    }

    public function test_a_license_plate_gets_the_layout_of_its_country()
    {
        $plate = Roads::licensePlate('netherlands', 'AB-123-C');

        $this->assertTrue($plate['euBar']);
        $this->assertSame('NL', $plate['prefix']);
        $this->assertSame('bg-yellow-500 text-black', $plate['plateClasses']);
        $this->assertFalse(Roads::licensePlate('nowhere', 'X')['euBar']);
    }
}
