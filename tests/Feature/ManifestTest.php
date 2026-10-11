<?php

namespace Tests\Feature;

use Tests\TestCase;

class ManifestTest extends TestCase
{
    public function test_the_manifest_makes_the_app_installable(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', config('app.name'))
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.2.purpose', 'maskable');
    }

    public function test_the_manifest_is_in_the_language_of_the_visitor(): void
    {
        $this->withSession(['locale' => 'nl'])
            ->get('/manifest.webmanifest')
            ->assertJsonPath('short_name', 'Voertuigen');
    }
}
