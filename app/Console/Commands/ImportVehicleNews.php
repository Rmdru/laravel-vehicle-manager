<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\VehicleNewsService;
use Illuminate\Console\Command;

class ImportVehicleNews extends Command
{
    protected $signature = 'import:vehicle-news';

    protected $description = 'Read the news, tests and recalls the tips can be about, for the countries, makes and models in use';

    public function handle(VehicleNewsService $news): void
    {
        $this->info($news->import().' articles stored');
    }
}
