<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trip from a start to a destination, possibly by way of other destinations,
 * with the routes that were planned for it.
 */
class Trip extends Model
{
    use HasUuids;

    public const TIME_MODES = ['departure', 'arrival'];

    public const STATUS_PLANNING = 'planning';

    /** The routes are planned; the stops along them not yet. */
    public const STATUS_ROUTED = 'routed';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_FAILED = 'failed';

    /** A planning that has not been heard of for this many minutes has stopped: the worker gives up on a job after 15. */
    public const STALE_AFTER_MINUTES = 20;

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'name',
        'time_mode',
        'time_at',
        'fuel_level',
        'status',
        'progress',
        'plan',
        'selected_route',
        'pinned_stops',
        'excluded_stops',
        'tips',
        'plan_version',
        'error',
        'planned_at',
    ];

    protected $attributes = [
        'plan_version' => 0,
    ];

    protected $casts = [
        'time_at' => 'datetime',
        'plan_version' => 'integer',
        'plan' => 'array',
        'progress' => 'array',
        'pinned_stops' => 'array',
        'excluded_stops' => 'array',
        'tips' => 'array',
        'planned_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    /**
     * The start, the destinations along the way and the destination, in order.
     *
     * @return HasMany<TripWaypoint, $this>
     */
    public function waypoints(): HasMany
    {
        return $this->hasMany(TripWaypoint::class)->orderBy('position');
    }
}
