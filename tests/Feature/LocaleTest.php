<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_switch_the_language()
    {
        $vehicle = $this->signInWithVehicle();

        $this->post(route('locale.update'), ['locale' => 'nl'])->assertSessionHasNoErrors();

        $this->assertSame('nl', $vehicle->user->refresh()->language);

        $this->get(route('timeline'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'nl')
                ->where('t.Timeline', 'Tijdlijn')
                ->where('navigation.0.items.1.title', 'Tijdlijn')
                ->etc());
    }

    public function test_unsupported_languages_are_rejected()
    {
        $this->signInWithVehicle();

        $this->post(route('locale.update'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
    }
}
