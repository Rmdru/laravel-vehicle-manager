<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Services\VehicleAdviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

class GenerateVehicleAdvices extends Command
{
    protected $signature = 'generate:vehicle-advices';

    protected $description = 'Generate the tips of every drivable vehicle whose facts changed or whose tips are a week old, in the language of its owner';

    public function handle(VehicleAdviceService $advices): void
    {
        $asked = false;

        Vehicle::query()
            ->where('status', VehicleStatus::Drivable->value)
            ->whereHas('user', fn ($query) => $query->recentlyLoggedIn())
            ->with('user')
            ->chunkById(50, function ($vehicles) use ($advices, &$asked) {
                foreach ($vehicles as $vehicle) {
                    $locale = $vehicle->user->language ?: config('app.locale');

                    // The free tier allows only so many requests per minute.
                    if ($asked) {
                        Sleep::for(config('vehicle_advice.pause_between'))->seconds();
                    }

                    try {
                        $asked = $advices->refresh($vehicle, $locale) !== null;
                    } catch (Throwable $exception) {
                        $asked = true;

                        Log::warning('Vehicle advice could not be generated', [
                            'vehicle' => $vehicle->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info('Vehicle advices generated');
    }
}
