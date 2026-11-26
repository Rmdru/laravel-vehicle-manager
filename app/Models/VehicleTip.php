<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        'source',
    ];

    protected $casts = [
        'due_date' => 'date',
        // The article it is based on: its address, the site and the date.
        'source' => 'array',
    ];

    public function getSubjectAttribute(): string
    {
        return $this->attributes['subject'] ?? VehicleAdvice::inferSubject((string) $this->key, $this->category);
    }

    /**
     * The tips handled recently enough to keep the model from making them
     * again: a tip that was done or not relevant can be relevant again in
     * time (config vehicle_advice.handled_for_days); a kept one is as long as
     * it is kept. Within that time only a change of the facts brings it back.
     *
     * @param  Builder<static>  $query
     */
    public function scopeRecentlyHandled(Builder $query): void
    {
        $query->where(function (Builder $query) {
            foreach (config('vehicle_advice.handled_for_days') as $status => $days) {
                $query->orWhere(fn (Builder $query) => $query->where('status', $status)
                    ->when($days !== null, fn (Builder $query) => $query->where('updated_at', '>=', now()->subDays($days))));
            }
        });
    }

    /**
     * The tips in force of a vehicle, by key.
     *
     * @return Collection<string, static>
     */
    public static function inForce(Vehicle $vehicle): Collection
    {
        return static::query()->where('vehicle_id', $vehicle->id)->recentlyHandled()->get()->keyBy('key');
    }

    /**
     * Whether a tip of an advice is hidden by a tip handled with its key: it
     * is, unless it came back for a change since, in an advice made after the
     * owner handled it (see VehicleAdviceService::suggestions()).
     *
     * @param  array<string, mixed>  $suggestion
     * @param  Collection<string, VehicleTip>  $inForce
     */
    public static function hides(array $suggestion, CarbonInterface $generatedAt, Collection $inForce): bool
    {
        $tip = $inForce->get($suggestion['key'] ?? '');

        return $tip !== null && (empty($suggestion['returns_because']) || ! $generatedAt->gt($tip->updated_at));
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
