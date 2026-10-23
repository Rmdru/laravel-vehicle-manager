<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A component in the maintenance plan of a vehicle: how often it is due, and
 * when it was done last as far as the maintenances do not tell.
 */
class VehicleComponent extends Model
{
    /** The intervals of config('maintenance_plan.components'). */
    public const DEFAULT = 'default';

    /** The intervals the AI model proposed for the make, model and engine. */
    public const AI = 'ai';

    /** The intervals the owner set. */
    public const MANUAL = 'manual';

    protected $fillable = [
        'vehicle_id',
        'key',
        'active',
        'interval_km',
        'interval_months',
        'source',
        'note',
        'done_date',
        'done_km',
    ];

    protected $casts = [
        'active' => 'boolean',
        'interval_km' => 'integer',
        'interval_months' => 'integer',
        'done_date' => 'date:Y-m-d',
        'done_km' => 'integer',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
