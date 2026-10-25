<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'vehicle_id',
        'name',
        'category',
        'quantity',
        'mileage',
        'component',
        'price',
        'date',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'category' => ProductCategory::class,
        'quantity' => 'float',
    ];

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * A fluid topped up at a mileage, such as engine oil.
     */
    public function isTopUp(): bool
    {
        return (bool) $this->category?->isFluid() && $this->mileage !== null && (float) $this->quantity > 0;
    }
}
