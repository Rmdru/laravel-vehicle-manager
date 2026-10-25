<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line on the list for the garage that is not a finding: a question, a
 * part to bring, a small job. Gone once the maintenance is recorded with it.
 */
class GarageListItem extends Model
{
    protected $fillable = [
        'vehicle_id',
        'text',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
