<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceTypeMaintenance;
use App\Enums\VehicleStatus;
use Carbon\Carbon;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

class Vehicle extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

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
        'is_private',
        'status',
        'fuel_types',
        'tank_capacity',
        'specifications',
        'notifications',
    ];

    protected $casts = [
        'purchase_date' => 'date:Y-m-d',
        'construction_date' => 'date:Y-m-d',
        'private' => 'boolean',
        'fuel_types' => 'array',
        'specifications' => 'array',
        'notifications' => 'array',
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

    protected static function booted()
    {
        static::addGlobalScope('ownVehicles', function (Builder $builder) {
            $builder->where('user_id', Auth::id());
        });
    }

    public function scopeSelected(Builder $query): void
    {
        $vehicleId = Session::get('Dashboard_filters', '')['vehicleId'] ?? Vehicle::latest()->first()->id;

        Session::put('vehicle_id', $vehicleId);

        $query->where([
            'id' => Session::get('vehicle_id'),
            'user_id' => Auth::id(),
        ]);
    }

    public function scopeOnlyDriveable(Builder $query): void
    {
        $query->where([
            'status' => 'drivable',
        ]);
    }

    public function getImageUrlAttribute()
    {
        $url = url(route('vehicle.image', ['vehicle' => $this->id]));

        return Http::head($url)->successful() ? $url : null;
    }

    public function getFullNameAttribute(): string
    {
        $brands = config('vehicles.brands');

        return $brands[$this->brand] . ' ' . $this->model;
    }

    public function getFullNameWithLicensePlateAttribute(): string
    {
        $brands = config('vehicles.brands');

        return $brands[$this->brand] . ' ' . $this->model . ' (' . $this->license_plate . ')';
    }

    public function getFuelStatusAttribute(): int
    {
        if ($this->refuelings->isNotEmpty() && $this->refuelings->where('fuel_type', 'Premium Unleaded (E10)')->count() > 0) {
            $latestRefueling = $this->refuelings->sortByDesc('date')->first();

            if (! empty($latestRefueling) && $latestRefueling->fuel_type = 'Premium Unleaded (E10)') {
                $diff = Carbon::parse($latestRefueling->date)->addMonths(2)->diffInDays(now());
                return (int) max(0, $diff - ($diff * 2));
            }
        }

        return 0;
    }

    public function getMaintenanceStatusAttribute(): array
    {
        $maintenanceTypes = ['small_maintenance', 'maintenance', 'big_maintenance'];

        $latestMaintenance = $this->maintenances->whereIn('type_maintenance', $maintenanceTypes)->sortByDesc('date')->first();

        if (! empty($latestMaintenance) && in_array($latestMaintenance->type_maintenance, $maintenanceTypes)) {
            $maintenanceDate = Carbon::parse($latestMaintenance->date ?? now())->addYear();
            $maintenanceDiff = $maintenanceDate->diffInDays(now());
            $timeDiffHumans = $maintenanceDate->diffForHumans();

            $timeTillMaintenance = max(0, $maintenanceDiff - ($maintenanceDiff * 2));

            $distanceTillMaintenance = 15000 + $latestMaintenance->mileage - $this->mileage_latest;
        }

        if (empty($latestMaintenance) || ! in_array($latestMaintenance->type_maintenance, $maintenanceTypes)) {
            $maintenanceDate = now()->addYear();
            $maintenanceDiff = $maintenanceDate->diffInDays(now());
            $timeTillMaintenance = max(0, $maintenanceDiff - ($maintenanceDiff * 2));
            $timeDiffHumans = $maintenanceDate->diffForHumans();
            $distanceTillMaintenance = 15000;
        }

        return [
            'time' => $timeTillMaintenance,
            'timeDiffHumans' => $timeDiffHumans,
            'distance' => $distanceTillMaintenance,
        ];
    }

    public function getApkStatusAttribute(): array
    {
        if ($this->maintenances->where('apk', true)->isNotEmpty()) {
            $latestApk = $this->maintenances->where('apk', true)->sortByDesc('date')->first();

            $apkDate = Carbon::parse($latestApk->date ?? now())->addYear();
            $apkDiff = $apkDate->diffInDays(now());
            $timeDiffHumans = $apkDate->diffForHumans();

            $timeTillApk = max(0, $apkDiff - ($apkDiff * 2));
        }

        if ($this->maintenances->where('apk', true)->isEmpty()) {
            $apkDiff = now()->addYear()->diffInDays(now());
            $timeTillApk = max(0, $apkDiff - ($apkDiff * 2));
            $timeDiffHumans = now()->addYear()->diffForHumans();
        }

        return [
            'time' => $timeTillApk,
            'timeDiffHumans' => $timeDiffHumans,
        ];
    }

    public function getAircoCheckStatusAttribute(): array
    {
        if ($this->maintenances->where('airco_check', true)->isNotEmpty()) {
            $latestAircoCheck = $this->maintenances->where('airco_check', true)->sortByDesc('date')->first();

            $aircoCheckDate = Carbon::parse($latestAircoCheck->date)->addYears(2);
            $timeTillAircoCheckDiff = $aircoCheckDate->diffInDays(now());
            $timeTillAircoCheck = max(0, $timeTillAircoCheckDiff - ($timeTillAircoCheckDiff * 2));
            $timeDiffHumans = $aircoCheckDate->diffForHumans();

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
            $timeDiffHumans = $date->diffForHumans();

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
            $timeDiffHumans = $date->diffForHumans();

            return [
                'time' => $timeTill,
                'timeDiffHumans' => $timeDiffHumans,
            ];
        }

        return [];
    }

    public function getInsuranceStatusAttribute(): array
    {
        if ($this->insurances->isNotEmpty()) {
            $insurance = $this->insurances->where('start_date', '<=', today())->sortByDesc('start_date')->first();

            $timeTillInsuranceDiff = $insurance->end_date->diffInDays(now());
            $timeTillInsuranceEndDate = max(0, $timeTillInsuranceDiff - ($timeTillInsuranceDiff * 2));
            $timeDiffHumans = $insurance->end_date->diffForHumans();

            return [
                'time' => $timeTillInsuranceEndDate,
                'timeDiffHumans' => $timeDiffHumans,
            ];
        }

        return [];
    }

    public function getTaxStatusAttribute(): array
    {
        if ($this->taxes->isNotEmpty()) {
            $tax = $this->taxes->where('start_date', '<=', today())->sortByDesc('start_date')->first();

            $timeTillTaxDiff = $tax->end_date->diffInDays(now());
            $timeTillTaxEndDate = max(0, $timeTillTaxDiff - ($timeTillTaxDiff * 2));
            $timeDiffHumans = $tax->end_date->diffForHumans();

            return [
                'time' => $timeTillTaxEndDate,
                'timeDiffHumans' => $timeDiffHumans,
            ];
        }

        return [];
    }

    public function getWashingStatusAttribute(): array
    {
        if ($this->reconditionings->isNotEmpty()) {
            $latestWashDate = $this->reconditionings->filter(function ($item) {
                $types = $item->type;
                return collect($types)->contains(function ($type) {
                    return str_contains($type, 'carwash') || str_contains($type, 'exterior_cleaning');
                });
            })->sortByDesc('date')
                ->first();

            $washDate = Carbon::parse($latestWashDate->date ?? now())->addMonth();
            $washDiff = $washDate->diffInDays(now());
            $timeDiffHumans = $washDate->diffForHumans();

            $timeTillWash = max(0, $washDiff - ($washDiff * 2));
        }

        if ($this->reconditionings->isEmpty()) {
            $washDate = now()->addMonth();
            $washDiff = $washDate->diffInDays(now());
            $timeTillWash = max(0, $washDiff - ($washDiff * 2));
            $timeDiffHumans = $washDate->diffForHumans();
        }

        return [
            'time' => $timeTillWash,
            'timeDiffHumans' => $timeDiffHumans,
        ];
    }

    public function getStatusBadge(string $vehicleId = '', string $item = '')
    {
        $selectedVehicle = Vehicle::selected()->first();

        if ($vehicleId) {
            $selectedVehicle = Vehicle::where('id', $vehicleId)->latest()->first();
        }

        $timeTillRefueling = $selectedVehicle->fuel_status ?? null;
        $maintenanceStatus = $selectedVehicle->maintenance_status ?? null;
        $timeTillApk = $selectedVehicle->apk_status['time'] ?? null;
        $timeTillAircoCheck = $selectedVehicle->airco_check_status['time'] ?? null;
        $timeTillInsuranceEndDate = $selectedVehicle->insurance_status['time'] ?? null;
        $timeTillTaxEndDate = $selectedVehicle->tax_status['time'] ?? null;
        $timeTillWashing = $selectedVehicle->washing_status['time'] ?? null;
        $timeTillTirePressure = $selectedVehicle->tire_pressure_status['time'] ?? null;
        $timeTillLiquidsCheck = $selectedVehicle->liquids_check_status['time'] ?? null;

        $priorities = [
            'success' => [
                'color' => 'success',
                'icon' => 'gmdi-check-r',
                'text' => __('OK'),
            ],
            'info' => [
                'color' => 'info',
                'icon' => 'gmdi-info-r',
                'text' => __('Notification'),
            ],
            'warning' => [
                'color' => 'warning',
                'icon' => 'gmdi-warning-r',
                'text' => __('Attention recommended'),
            ],
            'critical' => [
                'color' => 'danger',
                'icon' => 'gmdi-warning-r',
                'text' => __('Attention required'),
            ],
        ];

        if (in_array($selectedVehicle->status, [VehicleStatus::Suspended->value, VehicleStatus::Sold->value, VehicleStatus::Destroyed->value])) {
            return ! empty($item) ? $priorities['success'][$item] : $priorities['success'];
        }

        if (
            (! $timeTillRefueling && $timeTillRefueling < 10)
            || (! $maintenanceStatus['time'] && $maintenanceStatus['time'] < 31)
            || (! $maintenanceStatus['distance'] && $maintenanceStatus['distance'] < 1500)
            || (! $timeTillApk && $timeTillApk < 62)
            || (! $timeTillAircoCheck && $timeTillAircoCheck < 31)
            || (! $timeTillInsuranceEndDate && $timeTillInsuranceEndDate < 31)
        ) {
            return ! empty($item) ? $priorities['critical'][$item] : $priorities['critical'];
        }

        if (
            (! $timeTillRefueling && $timeTillRefueling < 30)
            || (! $maintenanceStatus['time'] && $maintenanceStatus['time'] < 62)
            || (! $maintenanceStatus['distance'] && $maintenanceStatus['distance'] < 3000)
            || (! $timeTillApk && $timeTillApk < 62)
            || (! $timeTillAircoCheck && $timeTillAircoCheck < 62)
            || (! $timeTillInsuranceEndDate && $timeTillInsuranceEndDate < 62)
            || (! $timeTillWashing && $timeTillWashing < 5)
            || (! $timeTillTirePressure && $timeTillTirePressure < 10)
            || (! $timeTillLiquidsCheck && $timeTillLiquidsCheck < 5)
        ) {
            return ! empty($item) ? $priorities['warning'][$item] : $priorities['warning'];
        }

        if (
            (! empty($timeTillTaxEndDate) && $timeTillTaxEndDate < 31)
            || (! empty($timeTillWashing) && $timeTillWashing < 10)
            || (! empty($timeTillTirePressure) && $timeTillTirePressure < 20)
            || (! empty($timeTillLiquidsCheck) && $timeTillLiquidsCheck < 10)
        ) {
            return ! empty($item) ? $priorities['info'][$item] : $priorities['info'];
        }

        return ! empty($item) ? $priorities['success'][$item] : $priorities['success'];
    }

    public function calculateMonthlyCosts(string $startDate = '', string $endDate = ''): array
    {
        if (empty($startDate)) {
            $startDate = now()->startOfMonth();
        }

        if (empty($endDate)) {
            $endDate = now()->endOfMonth();
        }

        $startDate = Carbon::parse($startDate);
        $endDate = Carbon::parse($endDate);
        $vehicleId = Vehicle::selected()->first()->id;

        $costTypes = [
            'Fuel' => [
                'model' => Refueling::class,
                'field' => 'total_price',
                'dateColumn' => 'date',
            ],
            'Maintenance' => [
                'model' => Maintenance::class,
                'field' => 'total_price',
                'dateColumn' => 'date',
            ],
            'Insurance' => [
                'model' => Insurance::class,
                'field' => 'price',
                'monthly' => true,
                'dateColumn' => 'start_date',
            ],
            'Tax' => [
                'model' => Tax::class,
                'field' => 'price',
                'monthly' => true,
                'dateColumn' => 'start_date',
            ],
            'Parking' => [
                'model' => Parking::class,
                'field' => 'price',
                'dateColumn' => 'start_time',
            ],
            'Toll' => [
                'model' => Toll::class,
                'field' => 'price',
                'dateColumn' => 'date',
            ],
            'Fine' => [
                'model' => Fine::class,
                'field' => 'price',
                'dateColumn' => 'date',
            ],
            'Vignette' => [
                'model' => Vignette::class,
                'field' => 'price',
                'dateColumn' => 'start_date',
            ],
            'Environmental sticker' => [
                'model' => EnvironmentalSticker::class,
                'field' => 'price',
                'dateColumn' => 'start_date',
            ],
            'Ferry' => [
                'model' => Ferry::class,
                'field' => 'price',
                'dateColumn' => 'start_date',
            ],
        ];

        $monthlyCosts = [];
        $labels = [];

        foreach ($costTypes as $label => $config) {
            $model = $config['model'];
            $field = $config['field'];
            $monthly = $config['monthly'] ?? false;
            $dateColumn = $config['dateColumn'] ?? 'date';

            if (empty($monthly)) {
                $data = Trend::model($model)
                    ->query(
                        $model::where('vehicle_id', $vehicleId)
                            ->whereBetween($dateColumn, [$startDate, $endDate])
                    )
                    ->between(
                        start: $startDate,
                        end: $endDate
                    )
                    ->perMonth()
                    ->sum($field);

                foreach ($data as $value) {
                    $month = Carbon::parse($value->date ?? $value->start_date ?? $value->start_time)->isoFormat('Y-MM');
                    if (! isset($monthlyCosts[$month])) {
                        $monthlyCosts[$month] = [];
                    }

                    if (! isset($monthlyCosts[$month][$label])) {
                        $monthlyCosts[$month][$label] = 0;
                    }

                    $monthlyCosts[$month][$label] += $value->aggregate;
                }

                if (empty($labels)) {
                    $labels = $data->map(fn (TrendValue $value) => str(Carbon::parse($value->date)->isoFormat('MMMM'))->ucfirst());
                }
            }

            if (! empty($monthly)) {
                $records = $model::where('vehicle_id', $vehicleId)
                    ->where(function ($query) use ($startDate, $endDate) {
                        $query->whereBetween('start_date', [$startDate, $endDate])
                              ->orWhereBetween('end_date', [$startDate, $endDate]);
                    })
                    ->get();

                foreach ($records as $record) {
                    $start = Carbon::parse($record->start_date)->startOfMonth();
                    $end = Carbon::parse($record->end_date)->endOfMonth();

                    while ($start <= $end) {
                        $month = $start->isoFormat('Y-MM');

                        if (! isset($monthlyCosts[$month])) {
                            $monthlyCosts[$month] = [];
                        }

                        if (! isset($monthlyCosts[$month][$label])) {
                            $monthlyCosts[$month][$label] = 0;
                        }

                        $monthlyCosts[$month][$label] += $record->$field;
                        $start->addMonth();
                    }
                }

            }
        }

        return [
            'monthlyCosts' => $monthlyCosts,
            'labels' => $labels,
        ];
    }

    /**
     * Get the user that owns the vehicle.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the refuelings that the vehicle has
     */
    public function refuelings(): HasMany
    {
        return $this->hasMany(Refueling::class);
    }

    /**
     * Get the maintenances that the vehicle has
     */
    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    /**
     * Get the insurances that the vehicle has
     */
    public function insurances(): HasMany
    {
        return $this->hasMany(Insurance::class);
    }

    /**
     * Get the taxes that the vehicle has
     */
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
}
