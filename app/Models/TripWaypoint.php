<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripWaypoint extends Model
{
    protected $fillable = [
        'trip_id',
        'position',
        'label',
        'latitude',
        'longitude',
        'stay_minutes',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'stay_minutes' => 'integer',
    ];

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
