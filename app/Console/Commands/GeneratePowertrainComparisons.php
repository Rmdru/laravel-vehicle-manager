<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Services\PowertrainComparisonFacts;
use App\Services\PowertrainComparisonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class GeneratePowertrainComparisons extends Command
{
    protected $signature = 'generate:powertrain-comparisons';

    protected $description = 'Choose the alternative powertrains of every drivable vehicle that has none, changed or whose alternatives are a year old, and estimate the prices without a feed again when they are old';

    public function handle(PowertrainComparisonService $comparisons, PowertrainComparisonFacts $facts): void
    {
        $asked = false;
        $countries = [];

        Vehicle::query()
            ->where('status', VehicleStatus::Drivable->value)
            ->whereHas('user', fn ($query) => $query->recentlyLoggedIn())
            ->with('user')
            ->chunkById(50, function ($vehicles) use ($comparisons, $facts, &$asked, &$countries) {
                foreach ($vehicles as $vehicle) {
                    $locale = $vehicle->user->language ?: config('app.locale');
                    $country = $vehicle->country_registration ?: 'netherlands';

                    try {
                        if (! isset($countries[$country])) {
                            $countries[$country] = true;
                            $this->pause($asked);
                            $asked = $facts->estimatePrices($country);
                        }

                        if ($comparisons->outdated($vehicle, $locale)) {
                            $this->pause($asked);
                            $asked = true;
                            $comparisons->generate($vehicle, $locale);
                        }
                    } catch (Throwable $exception) {
                        $asked = true;

                        Log::warning('Powertrain comparison could not be generated', [
                            'vehicle' => $vehicle->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info('Powertrain comparisons generated');
    }

    /** The free tier allows only so many requests per minute. */
    private function pause(bool $asked): void
    {
        if ($asked) {
            Sleep::for(config('powertrain_comparison.pause_between'))->seconds();
        }
    }
}
