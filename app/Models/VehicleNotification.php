<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notification of a vehicle under "Needs attention", from when it first
 * applied until it no longer did: dismissed by the owner or not.
 */
class VehicleNotification extends Model
{
    use MassPrunable;

    protected $fillable = [
        'vehicle_id',
        'key',
        'type',
        'text',
        'payload',
        'fingerprint',
        'first_seen_at',
        'last_seen_at',
        'notified_at',
        'dismissed_at',
        'resolved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'notified_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * The notifications that still apply.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * What the type and the text make of a notification: when it changes, a
     * dismissed notification comes back.
     */
    public static function fingerprint(string $type, string $text): string
    {
        return hash('sha256', $type.'|'.$text);
    }

    /**
     * Notifications that no longer apply for a year are of no use anymore.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('resolved_at', '<', now()->subYear());
    }
}
