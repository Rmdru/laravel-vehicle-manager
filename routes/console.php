<?php

declare(strict_types=1);

use App\Console\Commands\GenerateFuelAdvices;
use App\Console\Commands\GenerateVehicleAdvices;
use App\Console\Commands\ImportFuelPrices;
use App\Console\Commands\ImportVehicleData;
use App\Models\FuelAdvice;
use App\Models\VehicleAdvice;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ImportVehicleData::class)->daily();
Schedule::command(ImportFuelPrices::class)->daily()->at('01:00');
// After the fuel prices, which the advice is based on.
Schedule::command(GenerateFuelAdvices::class)->daily()->at('02:00');
// After the vehicle data of the RDW, which the tips are partly based on.
Schedule::command(GenerateVehicleAdvices::class)->daily()->at('02:30');
Schedule::command('model:prune', ['--model' => [FuelAdvice::class, VehicleAdvice::class]])->daily();
