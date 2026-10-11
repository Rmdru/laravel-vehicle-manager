<?php

declare(strict_types=1);

namespace App\Tables;

use App\Models\Insurance;
use App\Support\Html;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\TableBuilder;

class InsurancesTable extends VehicleRecordTable
{
    protected string $model = Insurance::class;

    protected string $dateColumn = 'start_date';

    public function configure(TableBuilder $table): void
    {
        $types = config('insurances.types');

        $table
            ->defaultSort('-start_date')
            ->withGlobalSearch(columns: ['insurance_company'])
            ->column('start_date', __('Start date'), sortable: true, as: fn ($value, Insurance $insurance) => $this->text($this->period($insurance->start_date, $insurance->end_date)))
            ->column('type', __('Type'), sortable: true, as: fn ($value) => new HtmlString(Html::badge($types[$value]['name'] ?? __('Unknown'), icon: $types[$value]['icon'] ?? null)))
            ->column('insurance_company', __('Insurance company'), sortable: true, as: fn ($value) => $this->text($value))
            ->column('price', __('Price per month'), sortable: true, as: fn ($value) => $this->amount($this->money($value)))
            ->column('invoice_day', __('Invoice day'), sortable: true, as: fn ($value) => $this->text($value.' '.__('th of the month')))
            ->paginate(25);

        $this->optionsFilter($table, 'type', __('Type'), collect($types)->map(fn (array $type) => (string) $type['name'])->all());
        $this->activeFilter($table);
    }
}
