<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenancePaymentMethod;
use App\Enums\MaintenanceTypeMaintenance;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Maintenance extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'vehicle_id',
        'date',
        'garage',
        'type_maintenance',
        'apk',
        'apk_date',
        'airco_check',
        'description',
        'tasks',
        'total_price',
        'mileage',
        'payment_method',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'apk' => 'boolean',
        'apk_date' => 'date:Y-m-d',
        'tasks' => 'array',
        'payment_method' => MaintenancePaymentMethod::class,
    ];

    /**
     * The maintenance types, such as an oil service and an inspection service
     * done together: a list, empty for none. One on its own is taken as well;
     * what is not a maintenance type is left out.
     *
     * @return Attribute<list<string>, list<string>|string|null>
     */
    protected function typeMaintenance(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => array_values(array_filter((array) json_decode((string) $value, true), 'is_string')),
            set: function (array|string|null $value) {
                $types = collect((array) $value)
                    ->filter(fn ($type) => is_string($type) && MaintenanceTypeMaintenance::tryFrom($type) !== null)
                    ->unique()
                    ->values()
                    ->all();

                return $types === [] ? null : json_encode($types);
            },
        );
    }

    /**
     * Get the vehicle of this maintenance.
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
