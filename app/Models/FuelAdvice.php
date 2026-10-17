<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Advice on when and where to refuel, written by an AI model from the fuel
 * prices and the refuelings of the vehicle.
 */
class FuelAdvice extends Model
{
    use HasUuids;
    use MassPrunable;

    public const ACTIONS = ['refuel_now', 'wait', 'refuel_abroad', 'anytime'];

    public const CONFIDENCES = ['low', 'medium', 'high'];

    protected $fillable = [
        'vehicle_id',
        'locale',
        'action',
        'country',
        'recommended_date',
        'confidence',
        'summary',
        'body',
        'reasons',
        'sources',
        'facts',
        'model',
        'grounded',
    ];

    protected $casts = [
        'recommended_date' => 'date',
        'reasons' => 'array',
        'sources' => 'array',
        'facts' => 'array',
        'grounded' => 'boolean',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Old advices are of no use anymore.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subYear());
    }
}
