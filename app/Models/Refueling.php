<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The percentage is missing on a refueling that has not been filled in yet.
 *
 * @property float|null $percentage
 */
class Refueling extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id',
        'date',
        'country',
        'gas_station',
        'fuel_type',
        'amount',
        'unit_price',
        'total_price',
        'mileage_begin',
        'mileage_end',
        'percentage',
        'fuel_consumption_onboard_computer',
        'fuel_consumption',
        'costs_per_kilometer',
        'tires',
        'climate_control',
        'routes',
        'driving_style',
        'avg_speed',
        'discount',
        'payment_method',
        'service_by_attendant',
        'charge_time',
        'comments',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'climate_control' => 'array',
        'routes' => 'array',
        'service_by_attendant' => 'boolean',
        'charge_time' => 'datetime:H:i',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * The most recent refuelings first: by the day of refueling, not by when they were entered.
     */
    public function scopeNewest(Builder $query): void
    {
        $query->orderByDesc('date')->orderByDesc('created_at');
    }

    /**
     * The refueling before this one, going by the mileage.
     */
    public function previous(): ?self
    {
        return self::query()
            ->where('vehicle_id', $this->vehicle_id)
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->where('mileage_end', '<=', $this->mileage_begin)
            ->orderByDesc('mileage_end')
            ->first();
    }

    /**
     * The refueling after this one, going by the mileage.
     */
    public function next(): ?self
    {
        return self::query()
            ->where('vehicle_id', $this->vehicle_id)
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->where('mileage_begin', '>=', $this->mileage_end)
            ->orderBy('mileage_begin')
            ->first();
    }

    /**
     * What was used since the previous refueling. When the tank is filled up every
     * time that is the amount refueled; otherwise the difference in how full the
     * tank was left counts as well.
     */
    public function consumedAmount(): float
    {
        $amount = (float) $this->amount;
        $capacity = (float) $this->vehicle?->tank_capacity;
        $previous = $capacity > 0 && $this->percentage !== null ? $this->previous() : null;

        if ($previous?->percentage === null) {
            return $amount;
        }

        $consumed = $amount + $capacity * ((float) $previous->percentage - (float) $this->percentage) / 100;

        return $consumed > 0 ? $consumed : $amount;
    }

    /**
     * Set the consumption and the costs per kilometer of the distance driven.
     */
    public function calculateConsumption(): void
    {
        $distance = $this->mileage_end - $this->mileage_begin;
        $amount = (float) $this->amount;
        $consumed = $this->consumedAmount();

        $this->fuel_consumption = round($consumed / $distance * 100, 2);
        // The price of what was used, which is not what was paid when the tank was not filled up.
        $this->costs_per_kilometer = round((float) $this->total_price * ($amount > 0 ? $consumed / $amount : 1) / $distance, 3);
    }

    public function getTenantName(): string
    {
        return $this->name ?? 'Default Tenant';
    }
}
