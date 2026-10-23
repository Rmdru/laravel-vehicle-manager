<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Services\NotificationCenter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncNotifications extends Command
{
    protected $signature = 'notifications:sync';

    protected $description = 'Bring the notifications of every drivable vehicle up to date, in the language of its owner, for the bell';

    public function handle(NotificationCenter $notifications): void
    {
        Vehicle::query()
            ->where('status', VehicleStatus::Drivable->value)
            ->whereHas('user', fn ($query) => $query->recentlyLoggedIn())
            ->with('user')
            ->chunkById(50, function ($vehicles) use ($notifications) {
                foreach ($vehicles as $vehicle) {
                    // The texts are stored as they are shown.
                    App::setLocale($vehicle->user->language ?: config('app.locale'));

                    try {
                        $notifications->sync($vehicle);
                    } catch (Throwable $exception) {
                        Log::warning('The notifications could not be brought up to date', [
                            'vehicle' => $vehicle->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info('Notifications brought up to date');
    }
}
