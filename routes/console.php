<?php

declare(strict_types=1);

use App\Console\Commands\GenerateFuelAdvices;
use App\Console\Commands\GeneratePowertrainComparisons;
use App\Console\Commands\GenerateVehicleAdvices;
use App\Console\Commands\ImportFuelPrices;
use App\Console\Commands\ImportStationPrices;
use App\Console\Commands\ImportVehicleData;
use App\Console\Commands\ImportVehicleNews;
use App\Console\Commands\QueueMonthlySummaryPushes;
use App\Console\Commands\RecordStationPrices;
use App\Console\Commands\SendPushNotifications;
use App\Console\Commands\SyncNotifications;
use App\Console\Commands\WarmTripPlannerCache;
use App\Models\FuelAdvice;
use App\Models\PushMessage;
use App\Models\StationPriceHistory;
use App\Models\VehicleAdvice;
use App\Models\VehicleNews;
use App\Models\VehicleNotification;
use App\Models\VehicleSummary;
use Illuminate\Support\Facades\Schedule;

// On one server: production ran the jobs without it twice, each time in the same second.
Schedule::command(ImportVehicleData::class)->daily()->withoutOverlapping()->onOneServer();
Schedule::command(ImportFuelPrices::class)->daily()->at('01:00')->withoutOverlapping()->onOneServer();
// After the fuel prices, which the advice is based on.
Schedule::command(GenerateFuelAdvices::class)->daily()->at('02:00')->withoutOverlapping()->onOneServer();
// Twice a week: what is on the pages of the sources changes by the week, and it costs requests of the free tier.
Schedule::command(ImportVehicleNews::class)->days([1, 4])->at('01:30')->withoutOverlapping()->onOneServer();
// After the vehicle data of the RDW and the news, which the tips are partly based on.
Schedule::command(GenerateVehicleAdvices::class)->daily()->at('02:30')->withoutOverlapping()->onOneServer();
// Only for vehicles that changed or whose alternatives are a year old: the costs are calculated every day anyway.
Schedule::command(GeneratePowertrainComparisons::class)->daily()->at('02:45')->withoutOverlapping()->onOneServer();
// The prices of all gas stations of the countries that publish them at once, for the station map and the trip planner.
Schedule::command(ImportStationPrices::class)->hourlyAt(20)->withoutOverlapping()->onOneServer()->runInBackground();
// The prices near the homes of the owners, a few times a day: for the history of the prices.
Schedule::command(RecordStationPrices::class)->cron('10 */'.config('fuel_advice.history_every_hours').' * * *')->withoutOverlapping()->onOneServer()->runInBackground();
// The places along the roads for the trip planner, a part every night.
Schedule::command(WarmTripPlannerCache::class)->daily()->at('03:00')->withoutOverlapping()->onOneServer()->runInBackground();
// After the advices and the tips, which can be notifications themselves.
Schedule::command(SyncNotifications::class)->daily()->at('03:30')->withoutOverlapping()->onOneServer();
// The summary of the month that ended is there to look back on.
Schedule::command(QueueMonthlySummaryPushes::class)->monthlyOn(1, '04:00')->onOneServer();
// Each push notification at its own moment: something critical at once, the rest at the moment of the owner.
Schedule::command(SendPushNotifications::class)->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [FuelAdvice::class, VehicleAdvice::class, VehicleSummary::class, VehicleNotification::class, PushMessage::class, StationPriceHistory::class, VehicleNews::class]])->daily()->onOneServer();
