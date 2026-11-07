<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A price of a fuel at a gas station, as a country publishes all of them at
 * once: imported every hour, see StationPriceImporter.
 *
 * @property string $country
 * @property string $source
 * @property string $source_id
 * @property string|null $name
 * @property float $latitude
 * @property float $longitude
 * @property string $fuel
 * @property string $fuel_type
 * @property float $price
 * @property string|null $service
 */
class StationPrice extends Model
{
    public const SELF = 'self';

    public const SERVED = 'served';

    protected $fillable = [
        'country',
        'source',
        'source_id',
        'name',
        'latitude',
        'longitude',
        'fuel',
        'fuel_type',
        'price',
        'service',
        'reported_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'price' => 'float',
        'reported_at' => 'datetime',
    ];
}
