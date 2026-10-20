<?php

namespace Tests\Feature;

use App\Support\GasStationLogo;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GasStationLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public function test_our_own_image_of_a_brand_goes_first()
    {
        $this->assertSame(asset('images/gas-stations/shell.png'), GasStationLogo::url('Shell Express Utrecht'));
        Http::assertNothingSent();
    }

    public function test_a_brand_without_an_image_gets_its_logo_from_iconify_once()
    {
        Http::fake([
            'https://api.iconify.design/thesvg-color.json*' => Http::response([
                'prefix' => 'thesvg-color',
                'icons' => ['circlek' => ['body' => '<path fill="#da291c" d="M0 0h24v24H0z"/>', 'width' => 24, 'height' => 24]],
            ]),
        ]);

        $logo = GasStationLogo::url('Circlek Houten');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $logo);
        $this->assertStringContainsString('#da291c', base64_decode(str($logo)->after(',')->toString()));
        Storage::disk('local')->assertExists('gas-station-logos/circlek.svg');
    }

    public function test_a_brand_iconify_has_no_logo_for_gets_the_default_image_and_is_not_looked_up_again()
    {
        Http::fake(['https://api.iconify.design/*' => Http::response([], 404)]);

        $this->assertSame(asset('images/gas-stations/default.png'), GasStationLogo::url('Buurpomp Utrecht'));
        Storage::disk('local')->assertExists('gas-station-logos/buurpomp.missing');
        Http::assertSentCount(2);

        Http::fake(['https://api.iconify.design/*' => fn () => $this->fail('Looked up again')]);
        GasStationLogo::url('Buurpomp Utrecht');
    }

    public function test_only_a_logo_of_the_brand_itself_is_known()
    {
        Http::fake(['https://api.iconify.design/*' => Http::response([], 404)]);

        $this->assertSame(asset('images/gas-stations/shell.png'), GasStationLogo::known('Shell Express Utrecht'));
        // Not the default image: the trip map shows the icon of a service area instead.
        $this->assertNull(GasStationLogo::known('Buurpomp Utrecht'));
        $this->assertNull(GasStationLogo::known('Default'));
    }

    public function test_a_gas_station_without_a_name_gets_the_default_image()
    {
        $this->assertSame(asset('images/gas-stations/default.png'), GasStationLogo::url(null));
        Http::assertNothingSent();
    }
}
