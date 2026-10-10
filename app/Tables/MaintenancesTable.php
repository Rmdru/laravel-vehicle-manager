<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\MaintenancePaymentMethod;
use App\Enums\MaintenanceTypeMaintenance;
use App\Models\Maintenance;
use App\Support\Html;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\TableBuilder;

class MaintenancesTable extends VehicleRecordTable
{
    protected string $model = Maintenance::class;

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['garage', 'description'])
            ->column('date', __('Date'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->withIcon('gmdi-calendar-month-r', $this->date($maintenance->date)))
            ->column('garage', __('Garage'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->isSmallCheck($maintenance) ? '' : $this->withIcon('mdi-garage', $value ?: __('Unknown')))
            ->column('type_maintenance', __('Type maintenance'), sortable: true, as: fn ($value) => $this->enumBadge(MaintenanceTypeMaintenance::class, $value))
            ->column('apk', __('MOT'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->isSmallCheck($maintenance)
                ? ''
                : new HtmlString(Html::badge($maintenance->apk ? __('MOT') : __('No MOT'))))
            ->column('mileage', __('Mileage'), sortable: true, as: fn ($value) => filled($value) ? $this->withIcon('gmdi-route', $value.' km') : '')
            ->column('total_price', __('Total price'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->isSmallCheck($maintenance) ? '' : $this->withIcon('mdi-hand-coin-outline', $this->money($value) ?: __('Unknown')))
            ->paginate(25);

        $this->enumFilter($table, 'type_maintenance', __('Type maintenance'), MaintenanceTypeMaintenance::class);
        $this->booleanFilter($table, 'apk', __('MOT'));
        $this->enumFilter($table, 'payment_method', __('Payment method'), MaintenancePaymentMethod::class);
    }

    private function isSmallCheck(Maintenance $maintenance): bool
    {
        return in_array($maintenance->type_maintenance, [
            MaintenanceTypeMaintenance::TirePressureChecked->value,
            MaintenanceTypeMaintenance::LiquidsChecked->value,
        ], true);
    }
}
