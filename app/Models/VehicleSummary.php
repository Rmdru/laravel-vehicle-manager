<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short summary of a month of a vehicle, written by an AI model from the
 * facts the app calculated: the distance, the costs and what stood out.
 */
class VehicleSummary extends Model
{
    use HasUuids;
    use MassPrunable;

    /** A look back at the previous month, early in a month. */
    public const PREVIOUS_MONTH = 'previous_month';

    /** The current month so far. */
    public const MONTH_TO_DATE = 'month_to_date';

    protected $fillable = [
        'vehicle_id',
        'locale',
        'month',
        'kind',
        'headline',
        'text',
        'facts',
        'facts_hash',
        'model',
    ];

    protected $casts = [
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
     * Summaries of months long gone are of no use anymore.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(3));
    }
}
