<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tile of which the places were fetched from OpenStreetMap, and when.
 */
class PoiTile extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'tile';

    protected $keyType = 'string';

    protected $fillable = [
        'tile',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
    ];
}
