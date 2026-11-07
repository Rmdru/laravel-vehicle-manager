<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a fuel cost at a gas station near the home of an owner on a day: the
 * lowest, the highest and the last of the snapshots of that day.
 */
class StationPriceHistory extends Model
{
    use MassPrunable;

    protected $table = 'station_price_history';

    protected $fillable = [
        'poi_id',
        'name',
        'country',
        'latitude',
        'longitude',
        'fuel_type',
        'date',
        'lowest_price',
        'highest_price',
        'last_price',
        'samples',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'latitude' => 'float',
        'longitude' => 'float',
        'lowest_price' => 'float',
        'highest_price' => 'float',
        'last_price' => 'float',
        'samples' => 'integer',
    ];

    /**
     * Prices of more than two years ago tell nothing about a refueling to come.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('date', '<', now()->subYears(2));
    }
}
