<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\ServiceType;
use App\Models\Service;
use TranquilTools\TableBuilder\TableBuilder;

class ServicesTable extends VehicleRecordTable
{
    protected string $model = Service::class;

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['name', 'comments'])
            ->column('type', __('Type'), sortable: true, as: fn ($value) => $this->enumBadge(ServiceType::class, $value))
            ->column('name', __('Name'), sortable: true)
            ->column('price', __('Price'), sortable: true, as: fn ($value) => $this->amount($this->money($value)))
            ->column('date', __('Date'), sortable: true, as: fn ($value, Service $service) => $this->text($this->date($service->date)))
            ->column('comments', __('Comments'), as: fn ($value) => $this->text((string) str((string) $value)->limit(80)))
            ->paginate(25);

        $this->enumFilter($table, 'type', __('Type'), ServiceType::class);
    }
}
