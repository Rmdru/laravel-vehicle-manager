<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\ApiKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;

/**
 * Estimates the prices in a country of the energy no feed of the app gives:
 * charging at a public charger, CNG, hydrogen and AdBlue. For the comparison
 * of the powertrains only, asked once a quarter per country.
 */
class EnergyPriceEstimator implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  string|null  $key  The API key to use, one of those configured; null for the configured one.
     */
    public function __construct(private readonly ?string $key = null)
    {
        //
    }

    public function provider(): Provider|string
    {
        return ApiKeys::provider(config('powertrain_comparison.provider'), $this->key);
    }

    public function model(): string
    {
        return config('powertrain_comparison.model');
    }

    public function timeout(): int
    {
        return config('powertrain_comparison.timeout');
    }

    public function instructions(): string
    {
        return <<<'TEXT'
            You estimate what a private driver without a subscription usually pays in a country today, in euros,
            taxes included:
            - electricity_ac_kwh: per kWh at a public slow (AC) charger, such as one in the street.
            - electricity_dc_kwh: per kWh at a fast (DC) charger along the motorway.
            - cng_kg: per kilogram of CNG at the pump.
            - hydrogen_kg: per kilogram of hydrogen at the pump.
            - adblue_l: per liter of AdBlue at the pump.
            Give the usual price, not the cheapest or the most expensive; 0 when it is not sold in the country.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'electricity_ac_kwh' => $schema->number()->required(),
            'electricity_dc_kwh' => $schema->number()->required(),
            'cng_kg' => $schema->number()->required(),
            'hydrogen_kg' => $schema->number()->required(),
            'adblue_l' => $schema->number()->required(),
        ];
    }

    public function question(string $country): string
    {
        return "Country: {$country}\nToday: ".today()->toDateString();
    }
}
