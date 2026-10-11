<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\ReconditioningExecutor;
use App\Enums\ReconditioningType;
use App\Models\Reconditioning;
use TranquilTools\TableBuilder\TableBuilder;

class ReconditioningsTable extends VehicleRecordTable
{
    protected string $model = Reconditioning::class;

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['location', 'description'])
            ->column('date', __('Date'), sortable: true, as: fn ($value, Reconditioning $reconditioning) => $this->text($this->date($reconditioning->date)))
            ->column('type', __('Type'), sortable: true, as: fn ($value) => $this->enumBadge(ReconditioningType::class, $value))
            ->column('executor', __('Executor'), sortable: true, as: fn ($value) => $this->enumBadge(ReconditioningExecutor::class, $value))
            ->column('price', __('Price'), sortable: true, as: fn ($value) => $this->amount($this->money($value)))
            ->column('location', __('Location'), sortable: true, as: fn ($value) => $this->text($value))
            ->column('description', __('Description'), as: fn ($value) => (string) str((string) $value)->limit(80))
            ->paginate(25);

        $this->enumFilter($table, 'type', __('Type'), ReconditioningType::class);
        $this->enumFilter($table, 'executor', __('Executor'), ReconditioningExecutor::class);
    }
}
