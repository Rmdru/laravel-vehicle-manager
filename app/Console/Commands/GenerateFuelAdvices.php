<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Services\FuelAdviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class GenerateFuelAdvices extends Command
{
    protected $signature = 'generate:fuel-advices';

    protected $description = 'Generate the fuel advice of every drivable vehicle, in the language of its owner';

    public function handle(FuelAdviceService $advices): void
    {
        $first = true;

        Vehicle::query()
            ->where('status', VehicleStatus::Drivable->value)
            ->whereNotIn('powertrain', ['electricity', 'hydrogen'])
            ->whereHas('user')
            ->with('user')
            ->chunkById(50, function ($vehicles) use ($advices, &$first) {
                foreach ($vehicles as $vehicle) {
                    $locale = $vehicle->user->language ?: config('app.locale');

                    // Already generated today, by hand.
                    if ($vehicle->fuelAdvices()->where('locale', $locale)->whereDate('created_at', today())->exists()) {
                        continue;
                    }

                    // The free tier allows only so many requests per minute.
                    if (! $first) {
                        Sleep::for(config('fuel_advice.pause_between'))->seconds();
                    }

                    $first = false;

                    try {
                        $advices->generate($vehicle, $locale);
                    } catch (Throwable $exception) {
                        Log::warning('Fuel advice could not be generated', [
                            'vehicle' => $vehicle->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info('Fuel advices generated');
    }
}
