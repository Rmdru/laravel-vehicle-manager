<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push notification to the devices of the owner of a vehicle, waiting for
 * its moment or sent: a notification of the bell, new tips or the monthly
 * summary.
 */
class PushMessage extends Model
{
    use MassPrunable;

    /** A notification of the bell: its urgency is its type. */
    public const NOTIFICATION = 'notification';

    public const TIPS = 'tips';

    public const SUMMARY = 'summary';

    public const URGENCIES = ['critical', 'warning', 'info'];

    protected $fillable = [
        'vehicle_id',
        'kind',
        'urgency',
        'vehicle_notification_id',
        'reference',
        'title',
        'body',
        'icon',
        'url',
        'send_after',
        'sent_at',
        'cancelled_at',
    ];

    protected $casts = [
        'send_after' => 'datetime',
        'sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<VehicleNotification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(VehicleNotification::class, 'vehicle_notification_id');
    }

    /**
     * The messages whose moment has come.
     *
     * @param  Builder<static>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('sent_at')->whereNull('cancelled_at')->where('send_after', '<=', now());
    }

    /**
     * Messages of three months ago are of no use anymore.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(3));
    }
}
