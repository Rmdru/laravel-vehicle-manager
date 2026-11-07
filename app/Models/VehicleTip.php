<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tip of the vehicle advice the owner did something with, with a copy of
 * the tip itself: the advice it came from is pruned in time.
 */
class VehicleTip extends Model
{
    /** Done, or kept for later reference: off the dashboard, still in the overview. */
    public const DONE = 'done';

    public const KEPT = 'kept';

    /** Gone altogether. */
    public const NOT_RELEVANT = 'not_relevant';

    public const STATUSES = [self::DONE, self::KEPT, self::NOT_RELEVANT];

    protected $fillable = [
        'vehicle_id',
        'key',
        'subject',
        'finding_id',
        'status',
        'locale',
        'category',
        'icon',
        'priority',
        'basis',
        'action',
        'due_date',
        'title',
        'body',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function getSubjectAttribute(): string
    {
        return $this->attributes['subject'] ?? VehicleAdvice::inferSubject((string) $this->key, $this->category);
    }

    /**
     * The tips handled recently enough to keep the model from making them
     * again: a tip that was done or not relevant can be relevant again in
     * time, such as a seasonal one the next year (config vehicle_advice.handled_for_days).
     *
     * @param  Builder<static>  $query
     */
    public function scopeRecentlyHandled(Builder $query): void
    {
        $query->where(function (Builder $query) {
            foreach (config('vehicle_advice.handled_for_days') as $status => $days) {
                $query->orWhere(fn (Builder $query) => $query->where('status', $status)->where('updated_at', '>=', now()->subDays($days)));
            }
        });
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
