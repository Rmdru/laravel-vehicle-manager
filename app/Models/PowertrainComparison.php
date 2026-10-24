<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A realistic alternative model per powertrain for a vehicle, chosen by an AI
 * model, with its estimates of what each costs: the app compares them with
 * the vehicle itself on the use of its owner.
 */
class PowertrainComparison extends Model
{
    use HasUuids;

    protected $fillable = [
        'vehicle_id',
        'locale',
        'current',
        'alternatives',
        'input_hash',
        'model',
    ];

    protected $casts = [
        'current' => 'array',
        'alternatives' => 'array',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
