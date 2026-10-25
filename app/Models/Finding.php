<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingSource;
use App\Enums\FindingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something that needs attention on a vehicle until it is resolved: advice
 * of the garage, a complaint of the owner, a defect the MOT found, or a
 * component of the maintenance plan to have done at the next visit.
 */
class Finding extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id',
        'source',
        'severity',
        'status',
        'date',
        'mileage',
        'title',
        'description',
        'garage',
        'estimated_cost',
        'components',
        'for_garage',
        'resolved_date',
        'maintenance_id',
        'rdw_code',
        'diagnosis',
        'diagnosed_at',
    ];

    protected $attributes = [
        'status' => 'open',
        'for_garage' => false,
    ];

    protected $casts = [
        'source' => FindingSource::class,
        'severity' => FindingSeverity::class,
        'status' => FindingStatus::class,
        'date' => 'date:Y-m-d',
        'resolved_date' => 'date:Y-m-d',
        'components' => 'array',
        'for_garage' => 'boolean',
        'estimated_cost' => 'float',
        'diagnosis' => 'array',
        'diagnosed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<Maintenance, $this>
     */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /**
     * The findings that still need attention.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', FindingStatus::Open->value);
    }

    /**
     * Resolved with a maintenance, or by hand.
     */
    public function resolve(?Maintenance $maintenance = null): void
    {
        $this->update([
            'status' => FindingStatus::Resolved,
            'resolved_date' => $maintenance === null ? today() : $maintenance->date,
            'maintenance_id' => $maintenance?->id,
            'for_garage' => false,
        ]);
    }
}
