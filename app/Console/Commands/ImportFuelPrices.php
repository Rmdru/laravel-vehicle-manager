<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuelPrice;
use App\Pipelines\ImportFuelPrices\Anwb;
use App\Pipelines\ImportFuelPrices\Belgium;
use App\Pipelines\ImportFuelPrices\CalculateFuelDetourAggregates;
use App\Pipelines\ImportFuelPrices\Germany;
use App\Pipelines\ImportFuelPrices\Luxembourg;
use App\Pipelines\ImportFuelPrices\Netherlands;
use App\Pipelines\ImportFuelPrices\Store;
use Illuminate\Console\Command;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportFuelPrices extends Command
{
    protected $signature = 'import:fuel-prices
        {--vehicle= : Also calculate the detours of this vehicle, whatever its status}
        {--detours-only : Only calculate the detours of that vehicle, from the prices already stored}';

    protected $description = 'Import fuel prices';

    public function handle(): void
    {
        $vehicle = $this->option('vehicle') ?: null;

        if ($this->option('detours-only')) {
            $this->detoursOnly($vehicle);

            return;
        }

        try {
            $data = [];

            app(Pipeline::class)
                ->send($data)
                ->through([
                    Netherlands::class,
                    Anwb::class,
                    Belgium::class,
                    Luxembourg::class,
                    Germany::class,
                    Store::class,
                    new CalculateFuelDetourAggregates($vehicle),
                ])
                ->thenReturn();

            Log::channel('fuel-prices')->info('Fuel prices imported successfully');
            $this->info('Fuel prices imported successfully');
        } catch (\Throwable $e) {
            Log::channel('fuel-prices')->error('Fuel prices import failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->error('Fuel prices import failed: '.$e->getMessage());
        }
    }

    /**
     * The detours of one vehicle, compared with the latest price of every
     * country and fuel type: without calling the sources again.
     */
    private function detoursOnly(?string $vehicle): void
    {
        if ($vehicle === null) {
            $this->error('The detours only can be calculated for a vehicle: pass --vehicle.');

            return;
        }

        $latest = FuelPrice::query()
            ->select('country', 'fuel_type', DB::raw('MAX(date) as latest_date'))
            ->groupBy('country', 'fuel_type');

        $data = FuelPrice::query()
            ->joinSub($latest, 'latest', fn ($join) => $join
                ->on('fuel_prices.country', '=', 'latest.country')
                ->on('fuel_prices.fuel_type', '=', 'latest.fuel_type')
                ->on('fuel_prices.date', '=', 'latest.latest_date'))
            ->get(['fuel_prices.date', 'fuel_prices.country', 'fuel_prices.fuel_type', 'fuel_prices.price'])
            ->map(fn (FuelPrice $price) => [
                'date' => $price->date->toDateString(),
                'country' => $price->country,
                'fuel_type' => $price->fuel_type,
                'price' => (float) $price->price,
            ])
            ->all();

        (new CalculateFuelDetourAggregates($vehicle, onlyThatVehicle: true))->handle($data, fn ($data) => $data);

        $this->info('Fuel detours calculated');
    }
}
