<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TripPlanner\ApiUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TripPlannerUsage extends Command
{
    protected $signature = 'trip-planner:usage
        {--days=7 : The number of days to show}';

    protected $description = 'Show the calls of the trip planner to TomTom and to the Overpass servers of the last days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $rows = ApiUsage::since(Carbon::now('UTC')->subDays($days - 1));
        $limit = (int) config('trip_planner.tomtom.daily_limit');

        $this->info('TomTom today: '.ApiUsage::tomTomToday().' of '.$limit.' calls'.(ApiUsage::tomTomSaving() ? ' (traffic and congestion are left out)' : '').'.');

        if (! $rows) {
            $this->line('No calls in the last '.$days.' days.');

            return self::SUCCESS;
        }

        $this->table(
            ['Date (UTC)', 'Service', 'Kind', 'Succeeded', 'Failed', 'Failed %', 'Average s'],
            array_map(function (array $row) {
                $calls = $row['succeeded'] + $row['failed'];

                return [
                    $row['date'],
                    $row['service'],
                    $row['kind'],
                    $row['succeeded'],
                    $row['failed'],
                    $calls ? round($row['failed'] / $calls * 100).'%' : '-',
                    $calls ? number_format($row['milliseconds'] / $calls / 1000, 1) : '-',
                ];
            }, $rows),
        );

        return self::SUCCESS;
    }
}
