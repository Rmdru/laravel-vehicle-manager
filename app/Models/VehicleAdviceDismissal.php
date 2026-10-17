<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A suggestion the driver marked as done or as not relevant.
 */
class VehicleAdviceDismissal extends Model
{
    public const REASONS = ['done', 'not_relevant'];

    protected $fillable = [
        'vehicle_id',
        'key',
        'reason',
        'title',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
