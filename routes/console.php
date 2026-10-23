<?php

declare(strict_types=1);

use App\Console\Commands\GenerateFuelAdvices;
use App\Console\Commands\GenerateVehicleAdvices;
use App\Console\Commands\ImportFuelPrices;
use App\Console\Commands\ImportVehicleData;
use App\Console\Commands\WarmTripPlannerCache;
use App\Models\FuelAdvice;
use App\Models\VehicleAdvice;
use App\Models\VehicleSummary;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ImportVehicleData::class)->daily();
Schedule::command(ImportFuelPrices::class)->daily()->at('01:00');
// After the fuel prices, which the advice is based on.
Schedule::command(GenerateFuelAdvices::class)->daily()->at('02:00');
// After the vehicle data of the RDW, which the tips are partly based on.
Schedule::command(GenerateVehicleAdvices::class)->daily()->at('02:30');
// The places along the roads for the trip planner, a part every night.
Schedule::command(WarmTripPlannerCache::class)->daily()->at('03:00')->withoutOverlapping()->runInBackground();
Schedule::command('model:prune', ['--model' => [FuelAdvice::class, VehicleAdvice::class, VehicleSummary::class]])->daily();
