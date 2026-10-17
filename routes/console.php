<?php

declare(strict_types=1);

use App\Console\Commands\GenerateFuelAdvices;
use App\Console\Commands\ImportFuelPrices;
use App\Console\Commands\ImportVehicleData;
use App\Models\FuelAdvice;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ImportVehicleData::class)->daily();
Schedule::command(ImportFuelPrices::class)->daily()->at('01:00');
// After the fuel prices, which the advice is based on.
Schedule::command(GenerateFuelAdvices::class)->daily()->at('02:00');
Schedule::command('model:prune', ['--model' => FuelAdvice::class])->daily();
