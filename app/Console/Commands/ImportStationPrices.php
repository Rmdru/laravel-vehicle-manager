<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TripPlanner\StationPriceImporter;
use Illuminate\Console\Command;

class ImportStationPrices extends Command
{
    protected $signature = 'trip-planner:import-prices
        {--country=* : Only these countries, ISO 2 codes such as FR}';

    protected $description = 'Import the prices of all gas stations of the countries that publish them at once (France, Spain, the United Kingdom, Italy)';

    public function handle(StationPriceImporter $importer): int
    {
        foreach ($importer->import(array_map('strtoupper', (array) $this->option('country'))) as $country => $count) {
            $count === null
                ? $this->warn("{$country}: the prices could not be read, the previous ones are kept.")
                : $this->info("{$country}: {$count} prices.");
        }

        return self::SUCCESS;
    }
}
