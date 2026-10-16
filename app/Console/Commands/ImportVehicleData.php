<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use App\Services\RdwService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ImportVehicleData extends Command
{
    protected $signature = 'import:vehicle-data';

    protected $description = 'Import vehicle data from RDW API to vehicles database table';

    public function __construct(
        private readonly RdwService $rdwService
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        Vehicle::query()
            ->whereNotNull('license_plate')
            ->chunkById(100, function ($vehicles) {
                foreach ($vehicles as $vehicle) {
                    $this->import($vehicle);
                }
            });
    }

    private function import(Vehicle $vehicle): void
    {
        $licensePlate = $vehicle->license_plate_normalized;
        $previous = $vehicle->rdw_data ?: [];
        $response = json_decode($this->rdwService->fetchVehicleDataByLicensePlate($licensePlate), true);

        // No answer at all: the RDW could not be reached, try again tomorrow.
        if (! is_array($response)) {
            $this->warn("The RDW could not be reached for vehicle ID {$vehicle->id}.");

            return;
        }

        if ($response === []) {
            $this->warn("No RDW data found for vehicle ID {$vehicle->id} with license plate {$vehicle->license_plate}.");

            // A license plate that was registered before and no longer is: exported or demolished.
            if ($previous !== [] && empty($previous['not_found_since'])) {
                $vehicle->rdw_data = [...$previous, 'not_found_since' => today()->toDateString()];
                $vehicle->saveQuietly();
            }

            return;
        }

        $rdwData = $response[0];

        // The indicator saves looking up the recalls of most vehicles.
        $rdwData['open_recalls'] = ($rdwData['openstaande_terugroepactie_indicator'] ?? 'Ja') === 'Nee'
            ? []
            : $this->rdwService->getOpenRecalls($licensePlate);

        $rdwData['fuel'] = json_decode($this->rdwService->fetchFuelDataByLicensePlate($licensePlate), true) ?: ($previous['fuel'] ?? []);
        $rdwData['inspection'] = $this->rdwService->getLatestInspectionDefects($licensePlate) ?? ($previous['inspection'] ?? null);
        $rdwData['apk_renewed'] = $this->apkRenewal($previous, $rdwData) ?? ($previous['apk_renewed'] ?? null);
        $rdwData['ownership_changed_at'] = $this->ownershipChange($previous, $rdwData) ?? ($previous['ownership_changed_at'] ?? null);

        $vehicle->rdw_data = $rdwData;

        // A status set by the user (sold, stolen, ...) is left alone.
        if (VehicleStatus::tryFrom((string) $vehicle->status)?->isManagedByRdw() ?? true) {
            $vehicle->status = VehicleStatus::fromRdw($rdwData)->value;
        }

        $vehicle->saveQuietly();

        $this->info("Updated vehicle ID {$vehicle->id} with RDW data with license plate {$vehicle->license_plate}.");
    }

    /**
     * A MOT expiry date that moved forward: the vehicle has passed a new MOT.
     *
     * @return array{previous_expiry: string, expiry: string, detected_at: string}|null
     */
    private function apkRenewal(array $previous, array $current): ?array
    {
        $before = $previous['vervaldatum_apk_dt'] ?? null;
        $after = $current['vervaldatum_apk_dt'] ?? null;

        if (blank($before) || blank($after) || ! Carbon::parse($after)->isAfter(Carbon::parse($before))) {
            return null;
        }

        return [
            'previous_expiry' => Carbon::parse($before)->toDateString(),
            'expiry' => Carbon::parse($after)->toDateString(),
            'detected_at' => today()->toDateString(),
        ];
    }

    /**
     * The day the vehicle was registered to someone else, when that changed.
     */
    private function ownershipChange(array $previous, array $current): ?string
    {
        $before = $previous['datum_tenaamstelling'] ?? null;
        $after = $current['datum_tenaamstelling'] ?? null;

        if (blank($before) || blank($after) || $before === $after) {
            return null;
        }

        return Carbon::createFromFormat('Ymd', $after)->toDateString();
    }
}
