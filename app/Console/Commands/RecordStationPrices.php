<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StationPriceRecorder;
use Illuminate\Console\Command;

class RecordStationPrices extends Command
{
    protected $signature = 'trip-planner:record-prices';

    protected $description = 'Keep what the fuel of each vehicle costs at the gas stations near the home of its owner, for the history of the prices';

    public function handle(StationPriceRecorder $recorder): int
    {
        $this->info($recorder->record().' prices kept.');

        return self::SUCCESS;
    }
}
