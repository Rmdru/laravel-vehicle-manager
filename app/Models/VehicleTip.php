<?php

declare(strict_types=1);

namespace App\Models;

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

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
