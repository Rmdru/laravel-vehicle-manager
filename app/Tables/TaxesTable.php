<?php

declare(strict_types=1);

namespace App\Tables;

use App\Models\Tax;
use TranquilTools\TableBuilder\TableBuilder;

class TaxesTable extends VehicleRecordTable
{
    protected string $model = Tax::class;

    protected string $dateColumn = 'start_date';

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-start_date')
            ->column('start_date', __('Start date'), sortable: true, as: fn ($value, Tax $tax) => $this->withIcon('gmdi-calendar-month-r', $this->period($tax->start_date, $tax->end_date)))
            ->column('price', __('Price per month'), sortable: true, as: fn ($value) => $this->withIcon('mdi-hand-coin-outline', $this->money($value)))
            ->column('invoice_day', __('Invoice day'), sortable: true, as: fn ($value) => $this->withIcon('gmdi-calendar-month-r', $value.' '.__('th of the month')))
            ->paginate(25);

        $this->activeFilter($table);
    }
}
