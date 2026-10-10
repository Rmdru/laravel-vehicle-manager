<?php

declare(strict_types=1);

namespace App\Tables;

use App\Models\Ferry;
use TranquilTools\TableBuilder\TableBuilder;

class FerriesTable extends VehicleRecordTable
{
    protected string $model = Ferry::class;

    protected string $dateColumn = 'start_date';

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-start_date')
            ->withGlobalSearch(columns: ['start_location', 'end_location'])
            ->column('start_location', __('Section'), sortable: true, as: fn ($value, Ferry $ferry) => $this->withIcon('gmdi-route-r', $ferry->start_location.' - '.$ferry->end_location))
            ->column('start_date', __('Date'), sortable: true, as: fn ($value, Ferry $ferry) => $this->withIcon('gmdi-calendar-month-r', $this->period($ferry->start_date, $ferry->end_date, 'll HH:mm')))
            ->column('price', __('Price'), sortable: true, as: fn ($value) => $this->withIcon('mdi-hand-coin-outline', $this->money($value)))
            ->paginate(25);
    }
}
