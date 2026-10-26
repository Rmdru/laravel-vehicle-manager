<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Services\Push\PushOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class QueueMonthlySummaryPushes extends Command
{
    protected $signature = 'push:monthly-summaries';

    protected $description = 'Tell the owners of drivable vehicles with push notifications that the summary of last month is there';

    public function handle(PushOutbox $push): void
    {
        $month = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();

        Vehicle::query()
            ->where('status', VehicleStatus::Drivable->value)
            ->whereHas('user', fn ($query) => $query->whereHas('pushSubscriptions'))
            ->with('user')
            ->chunkById(50, function ($vehicles) use ($push, $month) {
                foreach ($vehicles as $vehicle) {
                    $push->summary($vehicle, $month);
                }
            });

        $this->info('Monthly summaries put in line');
    }
}
