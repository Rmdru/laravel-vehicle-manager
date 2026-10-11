<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceTypeMaintenance;
use App\Services\OpenMeteoService;
use App\Services\VehicleCostsService;
use App\Support\Cost;
use App\Traits\LocationByIp;
use App\Traits\VehicleStats;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class Vehicle extends Model
{
    use HasFactory;
    use HasUuids;
    use LocationByIp;
    use SoftDeletes;
    use VehicleStats;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'brand',
        'model',
        'version',
        'engine',
        'mileage_start',
        'mileage_latest',
        'purchase_date',
        'construction_date',
        'purchase_price',
        'license_plate',
        'powertrain',
        'country_registration',
        'status',
        'fuel_types',
        'tank_capacity',
        'maintenance_interval_distance',
        'maintenance_interval_time',
        'specifications',
        'notifications',
        'rdw_data',
    ];

    protected $casts = [
        'purchase_date' => 'date:Y-m-d',
        'construction_date' => 'date:Y-m-d',
        'fuel_types' => 'array',
        'specifications' => 'array',
        'notifications' => 'array',
        'rdw_data' => 'array',
    ];

    protected $appends = [
        'fuel_status',
        'maintenance_status',
        'apk_status',
        'airco_check_status',
        'insurance_status',
        'tax_status',
        'washing_status',
        'tire_pressure_check_status',
        'liquids_check_status',
    ];

    /** The maintenance that restarts the maintenance interval. */
    public const MAINTENANCE_TYPES = ['small_maintenance', 'maintenance', 'big_maintenance'];

    private static array $monthlyCostsCache = [];

    private array $historicalMinTemps = [];

    public function scopeOwnVehicles(Builder $query): void
    {
        $query->where('user_id', auth()->id());
    }

    public function scopeOnlyDriveable(Builder $query): void
    {
        $query->where([
            'status' => 'drivable',
        ]);
    }

    public function getImagePathAttribute(): string
    {
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        foreach ($extensions as $extension) {
            $path = 'vehicles/'.$this->id.'.'.$extension;

            if (Storage::disk('private')->exists($path)) {
                return $path;
            }
        }

        return 'vehicles/'.$this->id.'.jpg';
    }

    public function getImageExistsAttribute(): bool
    {
        return Storage::disk('private')->exists($this->image_path);
    }

    public function getImageUrlAttribute(): string
    {
        if ($this->image_exists) {
            return url(route('vehicle.image', ['vehicle' => $this->id]));
        }

        return '';
    }

    public function getFullNameAttribute(): string
    {
        $brands = config('vehicles.brands');

        $brandName = $brands[$this->brand] ?? $this->brand;

        return $brandName.' '.$this->model;
    }

    public function getFullNameWithLicensePlateAttribute(): string
    {
        $brands = config('vehicles.brands');

        return $brands[$this->brand].' '.$this->model.' ('.$this->license_plate.')';
    }

    public function getLicensePlateNormalizedAttribute(): string
    {
        return str_replace([' ', '-', '+'], '', strtoupper($this->license_plate));
    }

    public function getFuelStatusAttribute(): ?array
    {
        $latestRefueling = $this->refuelings->sortBy([['date', 'desc'], ['created_at', 'desc']])->first();

        if (! empty($latestRefueling) && str($latestRefueling->fuel_type)->contains('E10', true)) {
            $diff = Carbon::parse($latestRefueling->date)->addMonths(2)->diffInDays(now());

            return [
                'time' => (int) max(0, $diff - ($diff * 2)),
            ];
        }

        return [];
    }

    public function getPeriodicSuperPlusAttribute(): array
    {
        $fuelTypes = [
            'Unleaded 95 (E10)',
            'Unleaded 95 (E5)',
        ];

        if ($this->refuelings->isNotEmpty()) {
            return [
                'recordCount' => $this->refuelings()
                    ->newest()
                    ->limit(3)
                    ->get()
                    ->whereIn('fuel_type', $fuelTypes)
                    ->count(),
            ];
        }

        return [
            'recordCount' => 0,
        ];
    }

    /**
     * When the next maintenance is due: after the interval in time and, when the
     * vehicle has one, after the interval in distance at the pace it is driven.
     *
     * @return array{maintenance: Maintenance, date: Carbon, distance: int|float|null, daysTillDistance: int|float|null}|null
     */
    public function nextMaintenance(): ?array
    {
        $latestMaintenance = $this->maintenances->whereIn('type_maintenance', self::MAINTENANCE_TYPES)->sortByDesc('date')->first();

        if (empty($latestMaintenance)) {
            return null;
        }

        $distance = null;
        $daysTillDistance = null;

        if (! empty($this->maintenance_interval_distance)) {
            $distance = $this->maintenance_interval_distance + $latestMaintenance->mileage - $this->mileage_latest;
            $monthlyDistance = $this->calculateAverageMonthlyDistance(vehicle: $this);
            $daysTillDistance = (int) $monthlyDistance > 30.44 ? $distance / ($monthlyDistance / 30.44) : $distance;
        }

        return [
            'maintenance' => $latestMaintenance,
            'date' => Carbon::parse($latestMaintenance->date ?? now())->addMonths((int) ($this->maintenance_interval_time ?: 12)),
            'distance' => $distance,
            'daysTillDistance' => $daysTillDistance,
        ];
    }

    public function getMaintenanceStatusAttribute(): array
    {
        $next = $this->nextMaintenance();

        if ($next === null || $next['distance'] === null) {
            return [];
        }

        $latestMaintenance = $next['maintenance'];
        $maintenanceDate = $next['date'];
        $maintenanceDiff = $maintenanceDate->diffInDays(now());
        $timeDiffHumans = $maintenanceDate->isFuture() ? $maintenanceDate->diffForHumans() : __('Now');

        $timeTillMaintenance = $maintenanceDiff - ($maintenanceDiff * 2);

        $distanceTillMaintenance = $next['distance'];
        $daysTillDistanceDeadline = $next['daysTillDistance'];
        $timeTillDistanceDeadlineHumans = now()->addDays($daysTillDistanceDeadline)->diffForHumans();
        $minDaysTillDeadline = min($daysTillDistanceDeadline, $timeTillMaintenance);

        return [
            'time' => $timeTillMaintenance,
            'timeDiffHumans' => $timeDiffHumans,
            'distance' => $distanceTillMaintenance,
            'daysTillDistanceDeadline' => $daysTillDistanceDeadline,
            'timeTillDistanceDeadlineHumans' => $timeTillDistanceDeadlineHumans,
            'minDaysTillDeadline' => $minDaysTillDeadline,
            'hasModal' => true,
            'modalTitle' => __('Maintenance deadline'),
            'modalHeading' => __('Maintenance deadline'),
            'data' => [
                [
                    'deadline_date' => $maintenanceDate->format('d-m-Y').' ('.$timeDiffHumans.')',
                    'deadline_distance' => __('Over').' '.$distanceTillMaintenance.'km ('.($latestMaintenance->mileage + $this->maintenance_interval_distance).' km)',
                    'deadline_distance_date' => now()->addDays($daysTillDistanceDeadline)->format('d-m-Y').' ('.$timeTillDistanceDeadlineHumans.')',
                ],
            ],
        ];
    }

    /**
     * The day the MOT expires: as the RDW knows it, else a year after the last one.
     */
    public function apkExpiryDate(): ?Carbon
    {
        if (! empty($this->rdw_data['vervaldatum_apk_dt'])) {
            return Carbon::parse($this->rdw_data['vervaldatum_apk_dt']);
        }

        $latestApk = $this->maintenances->where('apk', true)->sortByDesc('date')->first();

        return $latestApk ? Carbon::parse($latestApk->date ?? now())->addYear() : null;
    }

    public function getApkStatusAttribute(): array
    {
        $apkDate = $this->apkExpiryDate();

        if ($apkDate === null) {
            return [];
        }

        $apkDiff = $apkDate->diffInDays(now());
        $timeDiffHumans = $apkDate->isFuture() ? $apkDate->diffForHumans() : __('Now');

        $timeTillApk = max(0, $apkDiff - ($apkDiff * 2));

        return [
            'time' => $timeTillApk,
            'timeDiffHumans' => $timeDiffHumans,
            'hasModal' => true,
            'modalTitle' => __('MOT deadline'),
            'modalHeading' => __('MOT deadline'),
            'data' => [
                [
                    'deadline_date' => $apkDate->format('d-m-Y').' ('.$timeDiffHumans.')',
                ],
            ],
        ];
    }

    public function getAircoCheckStatusAttribute(): array
    {
        if ($this->maintenances->where('airco_check', true)->isNotEmpty()) {
            $latestAircoCheck = $this->maintenances->where('airco_check', true)->sortByDesc('date')->first();

            $aircoCheckDate = Carbon::parse($latestAircoCheck->date)->addYears(2);
            $timeTillAircoCheckDiff = $aircoCheckDate->diffInDays(now());
            $timeTillAircoCheck = max(0, $timeTillAircoCheckDiff - ($timeTillAircoCheckDiff * 2));
            $timeDiffHumans = $aircoCheckDate->isFuture() ? $aircoCheckDate->diffForHumans() : __('Now');

            return [
                'time' => $timeTillAircoCheck,
                'timeDiffHumans' => $timeDiffHumans,
            ];
        }

        return [];
    }

    public function getTirePressureCheckStatusAttribute(): array
    {
        if ($this->maintenances->where('type_maintenance', MaintenanceTypeMaintenance::TirePressureChecked->value)->isNotEmpty()) {
            $latest = $this->maintenances->where('type_maintenance', MaintenanceTypeMaintenance::TirePressureChecked->value)->sortByDesc('date')->first();

            $date = Carbon::parse($latest->date)->addMonths(2);
            $timeTillDiff = $date->diffInDays(now());
            $timeTill = max(0, $timeTillDiff - ($timeTillDiff * 2));
            $timeDiffHumans = $date->isFuture() ? $date->diffForHumans() : __('Now');

            return [
                'time' => $timeTill,
                'timeDiffHumans' => $timeDiffHumans,
            ];
        }

        return [];
    }

    public function getLiquidsCheckStatusAttribute(): array
    {
        if ($this->maintenances->where('type_maintenance', 'liquids_check')->isNotEmpty()) {
            $latest = $this->maintenances->where('type_maintenance', 'liquids_check')->sortByDesc('date')->first();

            $date = Carbon::parse($latest->date)->addMonths(2);
            $timeTillDiff = $date->diffInDays(now());
            $timeTill = max(0, $timeTillDiff - ($timeTillDiff * 2));
            $timeDiffHumans = $date->isFuture() ? $date->diffForHumans() : __('Now');

            return [
                'time' => $timeTill,
                'timeDiffHumans' => $timeDiffHumans,
            ];
        }

        return [];
    }

    public function getInsuranceStatusAttribute(): array
    {
        $rdwData = $this->rdw_data;

        if (! empty($rdwData['wam_verzekerd']) && $rdwData['wam_verzekerd'] !== 'Ja') {
            return [
                'time' => -1,
                'timeDiffHumans' => __('Now'),
            ];
        }

        // Insurances that have yet to start, or that have no end, have nothing to report.
        $insurance = $this->insurances->where('start_date', '<=', today())->sortByDesc('start_date')->first();

        if (empty($insurance?->end_date)) {
            return [];
        }

        $timeTillInsuranceDiff = $insurance->end_date->diffInDays(now());
        $timeTillInsuranceEndDate = max(0, $timeTillInsuranceDiff - ($timeTillInsuranceDiff * 2));
        $timeDiffHumans = $insurance->end_date->isFuture() ? $insurance->end_date->diffForHumans() : __('Now');

        return [
            'time' => $timeTillInsuranceEndDate,
            'timeDiffHumans' => $timeDiffHumans,
        ];
    }

    public function getTaxStatusAttribute(): array
    {
        $tax = $this->taxes->where('start_date', '<=', today())->sortByDesc('start_date')->first();

        if (empty($tax?->end_date)) {
            return [];
        }

        $timeTillTaxDiff = $tax->end_date->diffInDays(now());
        $timeTillTaxEndDate = max(0, $timeTillTaxDiff - ($timeTillTaxDiff * 2));
        $timeDiffHumans = $tax->end_date->isFuture() ? $tax->end_date->diffForHumans() : __('Now');

        return [
            'time' => $timeTillTaxEndDate,
            'timeDiffHumans' => $timeDiffHumans,
        ];
    }

    /**
     * Where the weather is looked up: the city of the owner, else wherever their IP address is.
     *
     * @return array<string, mixed>
     */
    private function weatherLocation(): array
    {
        $owner = $this->user;

        if ($owner?->latitude !== null && $owner?->longitude !== null) {
            return ['lat' => $owner->latitude, 'lon' => $owner->longitude];
        }

        return $this->getLocationDataByIp((string) request()->ip());
    }

    /**
     * The weather is cached per vehicle, so drop it when it has to be looked up elsewhere.
     */
    public function forgetWeather(): void
    {
        Cache::forget('vehicle_'.$this->id.'_historical_min_temps');
        Cache::store('database')->forget('vehicle_'.$this->id.'_historical_min_temps');
    }

    /**
     * The country the fuel prices abroad are compared with: where the vehicle is
     * registered, as long as the prices of that country are known.
     *
     * @param  list<string>  $countriesWithPrices
     */
    public function fuelPriceHomeCountry(array $countriesWithPrices): string
    {
        return in_array($this->country_registration, $countriesWithPrices, true)
            ? $this->country_registration
            : 'netherlands';
    }

    private function setHistoricalMinTemps(): void
    {
        if (! empty($this->historicalMinTemps)) {
            return;
        }

        $latestWash = (new Reconditioning)->latestWash($this->id);
        $location = empty($latestWash) ? [] : $this->weatherLocation();

        if (empty($location)) {
            return;
        }

        $this->historicalMinTemps = (new OpenMeteoService)->fetchHistoricalMinTempsInDateRange($location, $latestWash->date->format('Y-m-d'), now()->format('Y-m-d'));

        Cache::store('database')->put('vehicle_'.$this->id.'_historical_min_temps', $this->historicalMinTemps, now()->addDay());
    }

    private function getHistoricalMinTemps(): array
    {
        if (Cache::has('vehicle_'.$this->id.'_historical_min_temps')) {
            return Cache::get('vehicle_'.$this->id.'_historical_min_temps');
        }

        if (empty($this->historicalMinTemps)) {
            $this->setHistoricalMinTemps();
        }

        return $this->historicalMinTemps;
    }

    private function hasHistoricalMinTemps(array $historicalMinTemps): bool
    {
        return ! empty($historicalMinTemps['daily']['temperature_2m_min'])
            && is_array($historicalMinTemps['daily']['temperature_2m_min']);
    }

    private function getExteriorProtectionStatus(): ?Reconditioning
    {
        return $this->reconditionings()->where('type', 'exterior_protection')->orderByDesc('date')->first();
    }

    public function getWashingStatusAttribute(): array
    {
        $latestWash = (new Reconditioning)->latestWash($this->id);

        if (empty($latestWash)) {
            return [];
        }

        $washDate = Carbon::parse($latestWash->date ?? now())->addMonth();
        $washDiff = $washDate->diffInDays(now());
        $timeDiffHumans = $washDate->isFuture() ? $washDate->diffForHumans() : __('Now');

        $timeTillWash = max(0, $washDiff - ($washDiff * 2));

        return [
            'time' => $timeTillWash,
            'timeDiffHumans' => $timeDiffHumans,
        ];
    }

    public function getCarwashStatusAttribute(): array
    {
        if ($this->reconditionings->isEmpty()) {
            return [];
        }

        $washingStatus = $this->washing_status;

        if (empty($washingStatus) || (! empty($washingStatus) && $washingStatus['time'] >= 10)) {
            return [];
        }

        $historicalMinTemps = $this->getHistoricalMinTemps();

        if (! $this->hasHistoricalMinTemps($historicalMinTemps) || min($historicalMinTemps['daily']['temperature_2m_min']) >= 4) {
            return [];
        }

        return $washingStatus;
    }

    public function getSelfWashingStatusAttribute(): array
    {
        if ($this->reconditionings->isEmpty()) {
            return [];
        }

        $washingStatus = $this->washing_status;
        $exteriorProtectionStatus = $this->getExteriorProtectionStatus();

        if (empty($washingStatus) || (! empty($washingStatus) && $washingStatus['time'] >= 10)) {
            return [];
        }

        $historicalMinTemps = $this->getHistoricalMinTemps();
        $exteriorProtectionDate = Carbon::parse($exteriorProtectionStatus->date ?? now())->addMonths(3);
        $exteriorProtectionDiff = now()->diffInDays($exteriorProtectionDate);

        if (! $this->hasHistoricalMinTemps($historicalMinTemps) || min($historicalMinTemps['daily']['temperature_2m_min']) < 4 || $exteriorProtectionDiff <= 31) {
            return [];
        }

        return $washingStatus;
    }

    public function getSelfWashingProtectionStatusAttribute(): array
    {
        if ($this->reconditionings->isEmpty()) {
            return [];
        }

        $washingStatus = $this->washing_status;
        $exteriorProtectionStatus = $this->getExteriorProtectionStatus();

        if (empty($washingStatus) || (! empty($washingStatus) && $washingStatus['time'] >= 10) || empty($exteriorProtectionStatus)) {
            return [];
        }

        $exteriorProtectionDate = Carbon::parse($exteriorProtectionStatus->date ?? now())->addMonths(3);
        $exteriorProtectionDiff = now()->diffInDays($exteriorProtectionDate);

        if ($exteriorProtectionDiff > 31) {
            return [];
        }

        return $washingStatus;
    }

    public function getRecallStatusAttribute(): array
    {
        $openRecalls = $this->rdw_data['open_recalls'] ?? [];

        if (empty($openRecalls)) {
            return [
                'recordCount' => 0,
                'hasModal' => false,
                'data' => [],
            ];
        }

        return [
            'recordCount' => count($openRecalls),
            'hasModal' => true,
            'modalTitle' => __('Detailed Information'),
            'modalHeading' => __('Recalls'),
            'data' => $openRecalls,
        ];
    }

    public function calculateMonthlyCosts(string $startDate = '', string $endDate = ''): array
    {
        $vehicleId = $this->id;

        if (empty($startDate) || empty($endDate)) {
            $vehicleCostsService = new VehicleCostsService($this);
            $dateRange = $vehicleCostsService->getMonths($vehicleId);
            $startDate = $dateRange['startDate'];
            $endDate = $dateRange['endDate'];
        }

        $startKey = Carbon::parse($startDate)->toDateString();
        $endKey = Carbon::parse($endDate)->toDateString();
        $cacheKey = "monthly-costs:{$vehicleId}:{$startKey}:{$endKey}";

        if (array_key_exists($cacheKey, self::$monthlyCostsCache)) {
            return self::$monthlyCostsCache[$cacheKey];
        }

        $costTypes = Cost::types($this);
        $startDate = Carbon::parse($startDate);
        $endDate = Carbon::parse($endDate);

        $monthlyCosts = [];

        foreach ($costTypes as $label => $config) {
            $model = $config['model'];
            $priceField = $config['priceField'];
            $monthly = $config['monthly'] ?? false;
            $dateColumn = $config['dateColumn'] ?? 'date';

            if (empty($monthly)) {
                $data = $model::where('vehicle_id', $vehicleId)
                    ->whereBetween($dateColumn, [$startDate, $endDate])
                    ->get()
                    ->groupBy(function ($item) use ($dateColumn) {
                        return Carbon::parse($item->$dateColumn)->format('Y-m');
                    })
                    ->map(function ($row) use ($priceField) {
                        return $row->sum($priceField);
                    });

                foreach ($data as $month => $value) {
                    if (! isset($monthlyCosts[$month])) {
                        $monthlyCosts[$month] = [];
                    }

                    if (! isset($monthlyCosts[$month][$label])) {
                        $monthlyCosts[$month][$label] = 0;
                    }

                    $monthlyCosts[$month][$label] += $value;
                }
            }

            if (! empty($monthly)) {
                $records = $model::where('vehicle_id', $vehicleId)
                    ->where(function ($query) use ($startDate, $endDate) {
                        $query->whereBetween('start_date', [$startDate, $endDate])
                            ->orWhereBetween('end_date', [$startDate, $endDate])
                            ->orWhere(function ($subQuery) use ($startDate, $endDate) {
                                $subQuery->where('start_date', '<=', $startDate)
                                    ->where('end_date', '>=', $endDate);
                            });
                    })
                    ->get();

                $monthCursor = $startDate->copy()->startOfMonth();
                $endMonth = $endDate->copy()->endOfMonth()->startOfMonth();

                while ($monthCursor <= $endMonth) {
                    $month = $monthCursor->isoFormat('Y-MM');
                    $activeRecord = $this->getActiveMonthlyCostRecord($model, $records, $monthCursor);

                    if ($activeRecord) {
                        if (! isset($monthlyCosts[$month])) {
                            $monthlyCosts[$month] = [];
                        }

                        if (! isset($monthlyCosts[$month][$label])) {
                            $monthlyCosts[$month][$label] = 0;
                        }

                        $paymentDay = $activeRecord->invoice_day ?? 1;
                        $paymentDate = Carbon::createFromFormat('Y-m-d', "{$monthCursor->year}-{$monthCursor->month}-{$paymentDay}");
                        if ($paymentDate->between($startDate, $endDate)) {
                            $monthlyCosts[$month][$label] += $activeRecord->$priceField;
                        }
                    }

                    $monthCursor->addMonth();
                }
            }
        }

        $allMonths = collect();
        $currentMonth = $startDate->copy();
        while ($currentMonth <= $endDate) {
            $allMonths->push($currentMonth->isoFormat('Y-MM'));
            $currentMonth->addMonth();
        }

        foreach ($allMonths as $month) {
            if (! isset($monthlyCosts[$month])) {
                $monthlyCosts[$month] = [];
            }

            foreach ($costTypes as $label => $config) {
                if (! isset($monthlyCosts[$month][$label])) {
                    $monthlyCosts[$month][$label] = 0;
                }
            }
        }

        $monthlyCosts = collect($monthlyCosts)->sortKeys()->toArray();
        $labels = $allMonths
            ->map(function ($month) {
                return str(Carbon::createFromFormat('Y-m', $month)->isoFormat('MMM YY'))->ucfirst();
            })
            ->values()
            ->all();

        self::$monthlyCostsCache[$cacheKey] = [
            'monthlyCosts' => $monthlyCosts,
            'labels' => $labels,
        ];

        return self::$monthlyCostsCache[$cacheKey];
    }

    private function getActiveMonthlyCostRecord(string $modelClass, Collection $records, Carbon $month): ?Model
    {
        if (! method_exists($modelClass, 'getActiveMonthlyRecordForMonth')) {
            return null;
        }

        return $modelClass::getActiveMonthlyRecordForMonth($records, $month);
    }

    public function calculateAverageMonthlyCostsByType(string $startDate = '', string $endDate = ''): array
    {
        $costData = $this->calculateMonthlyCosts($startDate, $endDate);
        $monthCount = count($costData['labels']);

        if ($monthCount === 0) {
            return [];
        }

        $averages = [];

        foreach (Cost::types($this) as $label => $config) {
            $total = collect($costData['monthlyCosts'])
                ->sum(fn (array $monthCosts): float => $monthCosts[$label] ?? 0);

            $averages[$label] = round($total / $monthCount, 2);
        }

        return $averages;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refuelings(): HasMany
    {
        return $this->hasMany(Refueling::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    public function insurances(): HasMany
    {
        return $this->hasMany(Insurance::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(Tax::class);
    }

    public function parkings(): HasMany
    {
        return $this->hasMany(Parking::class);
    }

    public function tolls(): HasMany
    {
        return $this->hasMany(Toll::class);
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    public function reconditionings(): HasMany
    {
        return $this->hasMany(Reconditioning::class);
    }

    public function vignettes(): HasMany
    {
        return $this->hasMany(Vignette::class);
    }

    public function environmentalStickers(): HasMany
    {
        return $this->hasMany(EnvironmentalSticker::class);
    }

    public function ferries(): HasMany
    {
        return $this->hasMany(Ferry::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function accidents(): HasMany
    {
        return $this->hasMany(Accident::class);
    }
}
