<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * General suggestions for a vehicle, written by an AI model from everything
 * that is known about it: its state, its records and its costs.
 */
class VehicleAdvice extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $table = 'vehicle_advice';

    public const CATEGORIES = ['maintenance', 'season', 'known_issue', 'costs', 'value', 'legal', 'usage'];

    public const PRIORITIES = ['high', 'medium', 'low'];

    /** Where a suggestion comes from: the records in the app, or the general knowledge of the model. */
    public const BASES = ['app_data', 'general_knowledge'];

    /** What the driver can do about a suggestion, in the app. */
    public const ACTIONS = ['add_maintenance', 'add_reconditioning', 'view_insurances', 'view_taxes', 'view_vehicle', 'none'];

    protected $fillable = [
        'vehicle_id',
        'locale',
        'suggestions',
        'facts',
        'facts_hash',
        'model',
    ];

    protected $casts = [
        'suggestions' => 'array',
        'facts' => 'array',
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
        return static::query()->where('created_at', '<', now()->subMonths(3));
    }
}
