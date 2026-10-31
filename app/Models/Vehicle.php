<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceTypeMaintenance;
use App\Enums\Transmission;
use App\Services\OpenMeteoService;
use App\Services\VehicleCostsService;
use App\Support\Cost;
use App\Support\PremiumFuel;
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

/**
 * The construction date can be unknown.
 *
 * @property Carbon|null $construction_date
 */
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
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'brand',
        'model',
        'version',
        'engine',
        'power_hp',
        'body_type',
        'transmission',
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
        'maintenance_intervals',
        'specifications',
        'notifications',
        'push_preferences',
        'rdw_data',
    ];

    protected $casts = [
        'transmission' => Transmission::class,
        'purchase_date' => 'date:Y-m-d',
        'construction_date' => 'date:Y-m-d',
        'fuel_types' => 'array',
        'maintenance_intervals' => 'array',
        'specifications' => 'array',
        'notifications' => 'array',
        'push_preferences' => 'array',
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
    ];

    /** The maintenance types that can restart a maintenance interval. */
    public const MAINTENANCE_TYPES = ['small_maintenance', 'maintenance', 'big_maintenance', 'oil_service', 'inspection_service', 'longlife'];

    /** A maintenance with the airco check ticked: no type of its own, but it can restart an interval too. */
    public const AIRCO_CHECK = 'airco_check';

    /** What can restart a maintenance interval. */
    public const INTERVAL_TYPES = [...self::MAINTENANCE_TYPES, self::AIRCO_CHECK];

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

    public function getTransmissionAttribute($value): Transmission
    {
        if ($this->powertrain === 'electricity') {
            return Transmission::Automatic;
        }

        return $value instanceof Transmission ? $value : (Transmission::tryFrom((string) $value) ?? Transmission::Manual);
    }

    public function setTransmissionAttribute($value): void
    {
        $this->attributes['transmission'] = $this->powertrain === 'electricity'
            ? Transmission::Automatic->value
            : ($value instanceof Transmission ? $value->value : (string) $value);
    }

    /**
     * Which push notifications the owner wants for the vehicle, and when: what
     * they did not choose is as in config/push.php.
     *
     * @return array{critical: bool, warning: bool, info: bool, tips: bool, summary: bool, moment: ?string, quiet_from: ?string, quiet_until: ?string}
     */
    public function pushPreferences(): array
    {
        return [...config('push.defaults'), ...($this->push_preferences ?? [])];
    }

    public function getLicensePlateNormalizedAttribute(): string
    {
        return str_replace([' ', '-', '+'], '', strtoupper($this->license_plate));
    }

    /**
     * The days left before the E10 in the tank is too old, two months after
     * the date of the fuel. Only when the latest refueling was E10 (Unleaded 95
     * or Super Plus), and not before a full tank was refueled in all: a new
     * vehicle came with fuel of its own.
     */
    public function getFuelStatusAttribute(): ?array
    {
        $refuelings = $this->refuelings->sortBy([['date', 'desc'], ['created_at', 'desc']])->values();
        $latestRefueling = $refuelings->first();

        if (! empty($latestRefueling) && str($latestRefueling->fuel_type)->contains('E10', true)
            && ($fuelDate = $this->fuelDate($refuelings)) !== null) {
            $diff = $fuelDate->addMonths(2)->diffInDays(now());

            return [
                'time' => (int) max(0, $diff - ($diff * 2)),
            ];
        }

        return [];
    }

    /**
     * The date of the fuel in the tank: of the latest refueling from which on
     * at least a full tank of gasoline was refueled. A little topped up does
     * not make the old fuel in the tank new. Without a tank capacity the
     * latest refueling; null as long as no full tank was refueled in all.
     *
     * @param  Collection<int, Refueling>  $refuelings  Newest first.
     */
    private function fuelDate(Collection $refuelings): ?Carbon
    {
        if (! $this->tank_capacity) {
            return Carbon::parse($refuelings->first()->date);
        }

        $gasoline = [...PremiumFuel::REGULAR_GASOLINE, ...PremiumFuel::PREMIUM_GASOLINE];
        $liters = 0.0;

        foreach ($refuelings as $refueling) {
            // E5 as well: it thins out the old E10 just the same.
            if (in_array($refueling->fuel_type, $gasoline, true)) {
                $liters += (float) $refueling->amount;
            }

            if ($liters >= $this->tank_capacity) {
                return Carbon::parse($refueling->date);
            }
        }

        return null;
    }

    public function getPeriodicSuperPlusAttribute(): array
    {
        return ['recordCount' => $this->superPlusDue() ? 1 : 0];
    }

    /**
     * Whether the next refueling has to be Super Plus, to fill up with it 1 in 4
     * times: the last three refuelings were regular unleaded, or premium makes up
     * less than 25% of the gasoline refueled. Only for an engine that runs (at
     * least partly) on gasoline, and only from four refuelings on: before that a
     * single tank of regular unleaded would already call for Super Plus.
     */
    public function superPlusDue(): bool
    {
        if (! str_contains((string) $this->powertrain, 'gasoline') || $this->refuelings()->count() < 4) {
            return false;
        }

        $regular = PremiumFuel::REGULAR_GASOLINE;
        $premium = PremiumFuel::PREMIUM_GASOLINE;

        $lastThree = $this->refuelings()->newest()->limit(3)->pluck('fuel_type');

        if ($lastThree->count() === 3 && $lastThree->every(fn (?string $type) => in_array($type, $regular, true))) {
            return true;
        }

        $amounts = $this->refuelings()
            ->whereIn('fuel_type', [...$regular, ...$premium])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COALESCE(SUM(CASE WHEN fuel_type IN (?, ?, ?, ?) THEN amount ELSE 0 END), 0) as premium', $premium)
            ->toBase()
            ->first();

        return (float) $amounts->total > 0 && (float) $amounts->premium / (float) $amounts->total * 100 < 25;
    }

    /**
     * The maintenance intervals of the vehicle, such as an inspection service
     * and an oil service: the maintenance types that restart each, and its
     * interval in kilometers and in months (null: none). An interval without
     * types is restarted by any maintenance.
     *
     * @return list<array{types: list<string>, km: int|null, months: int|null}>
     */
    public function maintenanceIntervals(): array
    {
        return collect($this->maintenance_intervals ?? [])
            ->filter(fn ($interval) => is_array($interval))
            ->map(fn (array $interval) => [
                'types' => array_values(array_intersect((array) ($interval['types'] ?? []), self::INTERVAL_TYPES)) ?: self::MAINTENANCE_TYPES,
                'km' => (int) ($interval['km'] ?? 0) > 0 ? (int) $interval['km'] : null,
                'months' => (int) ($interval['months'] ?? 0) > 0 ? (int) $interval['months'] : null,
            ])
            ->filter(fn (array $interval) => $interval['km'] !== null || $interval['months'] !== null)
            ->values()
            ->all();
    }

    /**
     * The name of an interval: its maintenance types, or just maintenance
     * when any restarts it. Several as in a sentence, such as "Oil and
     * inspection service" ("Olie- en inspectieservice").
     *
     * @param  list<string>  $types
     */
    public static function intervalLabel(array $types): string
    {
        $label = fn (string $type) => $type === self::AIRCO_CHECK ? __('Airco check') : MaintenanceTypeMaintenance::from($type)->getLabel();

        $labels = array_diff(self::MAINTENANCE_TYPES, $types) === []
            ? [__('Maintenance'), ...array_map($label, array_values(array_diff($types, self::MAINTENANCE_TYPES)))]
            : array_map($label, $types);

        if (count($labels) < 2) {
            return $labels[0] ?? '';
        }

        // Names that end in the same word share it: "Oil and inspection service".
        $word = __('service');
        $shared = collect($labels)->every(fn (string $name) => mb_strlen($name) > mb_strlen($word)
            && mb_strtolower(mb_substr($name, -mb_strlen($word))) === mb_strtolower($word));
        $last = count($labels) - 1;

        $parts = array_map(function (string $name, int $index) use ($word, $shared, $last) {
            // Within a sentence in lower case; German keeps the capital of a noun.
            if ($index > 0 && app()->getLocale() !== 'de') {
                $name = mb_strtolower(mb_substr($name, 0, 1)).mb_substr($name, 1);
            }

            if (! $shared || $index === $last) {
                return $name;
            }

            // Separate words lose the shared one ("Oil"), a compound keeps a hyphen ("Olie-").
            $head = mb_substr($name, 0, -mb_strlen($word));

            return match (true) {
                str_ends_with($head, ' ') => rtrim($head),
                str_ends_with($head, '-') => $head,
                default => $head.'-',
            };
        }, $labels, array_keys($labels));

        return __(':list and :last', ['list' => implode(', ', array_slice($parts, 0, -1)), 'last' => end($parts)]);
    }

    /**
     * The options for what restarts a maintenance interval, by value: the
     * maintenance types and the airco check.
     *
     * @return array<string, string>
     */
    public static function intervalTypeOptions(): array
    {
        return collect(self::INTERVAL_TYPES)->mapWithKeys(fn (string $type) => [$type => self::intervalLabel([$type])])->all();
    }

    /**
     * When each maintenance interval is due: after its interval in time, and
     * after its interval in distance at the pace the vehicle is driven. The
     * date is when it is due by time, else by distance. An interval whose
     * maintenance types were never recorded counts from the latest
     * maintenance; without any, it is left out.
     *
     * @return list<array{label: string, types: list<string>, km: int|null, months: int|null, maintenance: Maintenance, date: Carbon, due: Carbon, distance: int|float|null, dueKm: int|null, daysTillDistance: int|float|null}>
     */
    public function nextMaintenances(): array
    {
        $monthlyDistance = null;

        return collect($this->maintenanceIntervals())
            ->map(function (array $interval) use (&$monthlyDistance) {
                $latestOf = fn (array $types) => $this->maintenances
                    ->filter(fn (Maintenance $maintenance) => array_intersect($maintenance->type_maintenance, $types) !== []
                        || (in_array(self::AIRCO_CHECK, $types, true) && $maintenance->airco_check))
                    ->sortByDesc('date')
                    ->first();

                // Never recorded as such yet, such as an oil service: from the latest maintenance, which usually included it.
                $latest = $latestOf($interval['types'])
                    ?? ($interval['types'] === [self::AIRCO_CHECK] ? null : $latestOf(self::MAINTENANCE_TYPES));

                if ($latest === null) {
                    return null;
                }

                $distance = null;
                $dueKm = null;
                $daysTillDistance = null;

                if ($interval['km'] !== null) {
                    $dueKm = (int) $latest->mileage + $interval['km'];
                    $distance = $dueKm - $this->mileage_latest;
                    $monthlyDistance ??= $this->calculateAverageMonthlyDistance(vehicle: $this);
                    $daysTillDistance = (int) $monthlyDistance > 30.44 ? $distance / ($monthlyDistance / 30.44) : $distance;
                }

                $byTime = $interval['months'] !== null ? Carbon::parse($latest->date)->addMonths($interval['months']) : null;
                $byDistance = $daysTillDistance !== null ? now()->addDays((int) round($daysTillDistance)) : null;

                return [
                    'label' => self::intervalLabel($interval['types']),
                    'types' => $interval['types'],
                    'km' => $interval['km'],
                    'months' => $interval['months'],
                    'maintenance' => $latest,
                    'date' => $byTime ?? $byDistance,
                    // Whichever comes first.
                    'due' => $byTime && $byDistance ? $byTime->min($byDistance) : ($byTime ?? $byDistance),
                    'distance' => $distance,
                    'dueKm' => $dueKm,
                    'daysTillDistance' => $daysTillDistance,
                ];
            })
            ->filter()
            ->sortBy(fn (array $next) => $next['due'])
            ->values()
            ->all();
    }

    /**
     * The maintenance interval that is due first; null when none is known.
     *
     * @return array{label: string, types: list<string>, maintenance: Maintenance, date: Carbon, due: Carbon, distance: int|float|null, dueKm: int|null, daysTillDistance: int|float|null}|null
     */
    public function nextMaintenance(): ?array
    {
        return $this->nextMaintenances()[0] ?? null;
    }

    public function getMaintenanceStatusAttribute(): array
    {
        $next = $this->nextMaintenance();

        if ($next === null) {
            return [];
        }

        $byTime = $next['date'];
        $timeDiffHumans = $byTime->isFuture() ? $byTime->diffForHumans() : __('Now');
        $timeTillMaintenance = (int) now()->diffInDays($byTime);
        $daysTillDistanceDeadline = $next['daysTillDistance'];
        $timeTillDistanceDeadlineHumans = $daysTillDistanceDeadline === null ? null : now()->addDays($daysTillDistanceDeadline)->diffForHumans();

        return [
            'label' => $next['label'],
            'time' => $timeTillMaintenance,
            'timeDiffHumans' => $timeDiffHumans,
            'distance' => $next['distance'],
            'daysTillDistanceDeadline' => $daysTillDistanceDeadline,
            'timeTillDistanceDeadlineHumans' => $timeTillDistanceDeadlineHumans,
            'minDaysTillDeadline' => $daysTillDistanceDeadline === null ? $timeTillMaintenance : min($daysTillDistanceDeadline, $timeTillMaintenance),
            'hasModal' => true,
            'modalTitle' => $next['label'],
            'modalHeading' => __('Maintenance deadline'),
            'data' => [
                array_filter([
                    'deadline_date' => $byTime->format('d-m-Y').' ('.$timeDiffHumans.')',
                    'deadline_distance' => $next['dueKm'] === null ? null : __('Over').' '.$next['distance'].'km ('.$next['dueKm'].' km)',
                    'deadline_distance_date' => $daysTillDistanceDeadline === null ? null : now()->addDays($daysTillDistanceDeadline)->format('d-m-Y').' ('.$timeTillDistanceDeadlineHumans.')',
                ]),
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

        if (empty($insurance->end_date)) {
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

        if (empty($tax->end_date)) {
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
        // The owner can be gone (soft deleted), whatever the foreign key says.
        $owner = $this->getRelationValue('user');

        if ($owner instanceof User && $owner->latitude !== null && $owner->longitude !== null) {
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
     * Whether the vehicle can run on the fuel: one of its compatible fuel
     * types. A vehicle without them (from before they had to be filled in)
     * is taken to run on any.
     */
    public function suitableForFuel(string $fuelType): bool
    {
        return empty($this->fuel_types) || in_array($fuelType, $this->fuel_types, true);
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

    /**
     * Empty when the vehicle was never washed.
     *
     * @return array{time: int|float, timeDiffHumans: string}|array{}
     */
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

        if (empty($washingStatus) || $washingStatus['time'] >= 10) {
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

        if (empty($washingStatus) || $washingStatus['time'] >= 10) {
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

        if (empty($washingStatus) || $washingStatus['time'] >= 10 || empty($exteriorProtectionStatus)) {
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

    /**
     * A new MOT the RDW registered, as long as no maintenance with a MOT has been
     * added for it. After 90 days it is no longer news.
     */
    public function getApkRenewedStatusAttribute(): array
    {
        $renewal = $this->rdw_data['apk_renewed'] ?? null;
        $detectedAt = filled($renewal['detected_at'] ?? null) ? Carbon::parse($renewal['detected_at']) : null;

        // A MOT can be registered some time after the inspection.
        $registered = $detectedAt?->copy()->subDays(60);

        if ($detectedAt === null
            || $detectedAt->isBefore(today()->subDays(90))
            || $this->maintenances->contains(fn (Maintenance $maintenance) => $maintenance->apk && $maintenance->date->gte($registered))) {
            return ['recordCount' => 0];
        }

        $inspection = $this->latestInspection($registered);

        return [
            'recordCount' => 1,
            'url' => route('maintenances.create', ['rdw_apk' => 1]),
            'urlLabel' => __('Add maintenance'),
            'hasModal' => $inspection !== null,
            'data' => $inspection === null ? [] : $this->inspectionDetails($inspection),
        ];
    }

    /**
     * The defects found at the latest inspection the RDW knows of, when it was
     * not before the given day.
     *
     * @return array{date: string, type: string, defects: list<array<string, mixed>>}|null
     */
    public function latestInspection(?Carbon $notBefore = null): ?array
    {
        $inspection = $this->rdw_data['inspection'] ?? null;

        if (empty($inspection['date']) || ($notBefore && Carbon::parse($inspection['date'])->isBefore($notBefore))) {
            return null;
        }

        return $inspection;
    }

    /**
     * The inspection as rows of label and value, as the details of a notification.
     *
     * @param  array{date: string, type: string, defects: list<array<string, mixed>>}  $inspection
     * @return list<array<string, string>>
     */
    public function inspectionDetails(array $inspection): array
    {
        return [
            ['inspection_date' => Carbon::parse($inspection['date'])->isoFormat('ll'), 'inspection_type' => $inspection['type']],
            ...collect($inspection['defects'])->map(fn (array $defect) => array_filter([
                'defect' => $defect['description'],
                'article' => $defect['article'],
                'number' => $defect['count'] > 1 ? (string) $defect['count'] : null,
            ]))->all(),
        ];
    }

    /**
     * Registered to someone else after the vehicle was bought: sold?
     */
    public function getOwnershipStatusAttribute(): array
    {
        $changedAt = $this->rdw_data['ownership_changed_at'] ?? null;

        if (blank($changedAt) || ($this->purchase_date && ! Carbon::parse($changedAt)->isAfter($this->purchase_date))) {
            return ['recordCount' => 0];
        }

        return [
            'recordCount' => 1,
            'hasModal' => true,
            'data' => [['registration_date' => Carbon::parse($changedAt)->isoFormat('ll')]],
            'url' => route('vehicles.edit', $this),
            'urlLabel' => __('Edit vehicle'),
        ];
    }

    /**
     * Exported according to the RDW, or no longer registered in the Netherlands at all.
     */
    public function getExportStatusAttribute(): array
    {
        $exported = ($this->rdw_data['export_indicator'] ?? null) === 'Ja';
        $notFoundSince = $this->rdw_data['not_found_since'] ?? null;

        if (! $exported && blank($notFoundSince)) {
            return ['recordCount' => 0];
        }

        return [
            'recordCount' => 1,
            'url' => route('vehicles.edit', $this),
            'urlLabel' => __('Edit vehicle'),
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
                        $paymentDate = $monthCursor->copy()->day(min($paymentDay, $monthCursor->daysInMonth));
                        if ($paymentDate->between($startDate, $endDate)) {
                            $monthlyCosts[$month][$label] += $activeRecord->$priceField;
                        }
                    }

                    $monthCursor->addMonth();
                }
            }
        }

        // Walk from the first of the month: from e.g. the 31st, addMonth()
        // overflows and skips the shorter months.
        $allMonths = collect();
        $currentMonth = $startDate->copy()->startOfMonth();
        while ($currentMonth <= $endDate) {
            $allMonths->push($currentMonth->copy());
            $currentMonth->addMonth();
        }

        // Exactly one entry per label, in the same order.
        $monthlyCosts = $allMonths
            ->mapWithKeys(fn (Carbon $month) => [
                $month->isoFormat('Y-MM') => collect(array_keys($costTypes))
                    ->mapWithKeys(fn (string $label) => [$label => $monthlyCosts[$month->isoFormat('Y-MM')][$label] ?? 0])
                    ->all(),
            ])
            ->all();
        $labels = $allMonths
            ->map(fn (Carbon $month) => (string) str($month->isoFormat('MMM YY'))->ucfirst())
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Refueling, $this>
     */
    public function refuelings(): HasMany
    {
        return $this->hasMany(Refueling::class);
    }

    /**
     * @return HasMany<FuelAdvice, $this>
     */
    public function fuelAdvices(): HasMany
    {
        return $this->hasMany(FuelAdvice::class);
    }

    /**
     * @return HasMany<VehicleAdvice, $this>
     */
    public function vehicleAdvices(): HasMany
    {
        return $this->hasMany(VehicleAdvice::class);
    }

    /**
     * @return HasMany<VehicleSummary, $this>
     */
    public function vehicleSummaries(): HasMany
    {
        return $this->hasMany(VehicleSummary::class);
    }

    /**
     * @return HasMany<PowertrainComparison, $this>
     */
    public function powertrainComparisons(): HasMany
    {
        return $this->hasMany(PowertrainComparison::class);
    }

    /**
     * @return HasMany<VehicleComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(VehicleComponent::class);
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * @return HasMany<GarageListItem, $this>
     */
    public function garageListItems(): HasMany
    {
        return $this->hasMany(GarageListItem::class);
    }

    /**
     * @return HasMany<Maintenance, $this>
     */
    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    /**
     * @return HasMany<Insurance, $this>
     */
    public function insurances(): HasMany
    {
        return $this->hasMany(Insurance::class);
    }

    /**
     * @return HasMany<Tax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(Tax::class);
    }

    /**
     * @return HasMany<Parking, $this>
     */
    public function parkings(): HasMany
    {
        return $this->hasMany(Parking::class);
    }

    /**
     * @return HasMany<Toll, $this>
     */
    public function tolls(): HasMany
    {
        return $this->hasMany(Toll::class);
    }

    /**
     * @return HasMany<Fine, $this>
     */
    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    /**
     * @return HasMany<Reconditioning, $this>
     */
    public function reconditionings(): HasMany
    {
        return $this->hasMany(Reconditioning::class);
    }

    /**
     * @return HasMany<Vignette, $this>
     */
    public function vignettes(): HasMany
    {
        return $this->hasMany(Vignette::class);
    }

    /**
     * @return HasMany<EnvironmentalSticker, $this>
     */
    public function environmentalStickers(): HasMany
    {
        return $this->hasMany(EnvironmentalSticker::class);
    }

    /**
     * @return HasMany<Ferry, $this>
     */
    public function ferries(): HasMany
    {
        return $this->hasMany(Ferry::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<Accident, $this>
     */
    public function accidents(): HasMany
    {
        return $this->hasMany(Accident::class);
    }
}
