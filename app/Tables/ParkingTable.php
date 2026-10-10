<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\ParkingPaymentMethod;
use App\Enums\ParkingType;
use App\Models\Parking;
use TranquilTools\TableBuilder\TableBuilder;

class ParkingTable extends VehicleRecordTable
{
    protected string $model = Parking::class;

    protected string $dateColumn = 'start_time';

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-start_time')
            ->withGlobalSearch(columns: ['location', 'company'])
            ->column('start_time', __('Date and time'), sortable: true, as: fn ($value, Parking $parking) => $this->withIcon('gmdi-calendar-month-r', $this->period($parking->start_time, $parking->end_time, 'll HH:mm')))
            ->column('location', __('Location'), sortable: true, as: fn ($value) => $this->withIcon('gmdi-location-on-r', $value))
            ->column('company', __('Company'), sortable: true, as: fn ($value) => $this->withIcon('mdi-office-building', $value))
            ->column('price', __('Price'), sortable: true, as: fn ($value) => $this->withIcon('mdi-hand-coin-outline', $this->money($value)))
            ->column('type', __('Type'), sortable: true, as: fn ($value) => $this->enumBadge(ParkingType::class, $value))
            ->column('payment_method', __('Payment method'), sortable: true, as: fn ($value) => $this->enumBadge(ParkingPaymentMethod::class, $value))
            ->paginate(25);

        $this->enumFilter($table, 'type', __('Type'), ParkingType::class);
        $this->enumFilter($table, 'payment_method', __('Payment method'), ParkingPaymentMethod::class);
    }
}
