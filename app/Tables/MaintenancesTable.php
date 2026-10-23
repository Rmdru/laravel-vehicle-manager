<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\MaintenancePaymentMethod;
use App\Enums\MaintenanceTypeMaintenance;
use App\Models\Maintenance;
use App\Support\Html;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\TableBuilder;

class MaintenancesTable extends VehicleRecordTable
{
    protected string $model = Maintenance::class;

    /** The maintenance types of the checks of the maintenance plan. */
    private const CHECKS = ['tire_pressure_checked', 'liquids_checked'];

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['garage', 'description'])
            ->column('date', __('Date'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->text($this->date($maintenance->date)))
            ->column('garage', __('Garage'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->isSmallCheck($maintenance) ? '' : $this->text($value ?: __('Unknown')))
            ->column('type_maintenance', __('Type maintenance'), sortable: true, as: fn ($value) => $this->enumBadge(MaintenanceTypeMaintenance::class, $value))
            ->column('apk', __('MOT'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->isSmallCheck($maintenance)
                ? ''
                : new HtmlString(Html::badge($maintenance->apk ? __('MOT') : __('No MOT'))))
            ->column('mileage', __('Mileage'), sortable: true, as: fn ($value) => filled($value) ? $this->text($value.' km') : '')
            ->column('total_price', __('Total price'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->isSmallCheck($maintenance) ? '' : $this->amount($this->money($value) ?: __('Unknown')))
            ->paginate(25);

        // The checks of the maintenance plan (tire pressure, fluids) would crowd the list: hidden unless asked for.
        $table->callbackFilter('checks', ['hidden' => __('Without checks'), 'shown' => __('With checks')], fn (Builder $query, string $value) => $value === 'hidden'
            ? $query->where(fn (Builder $query) => $query->whereNull('type_maintenance')->orWhereNotIn('type_maintenance', self::CHECKS))
            : $query, __('Checks'), defaultValue: 'hidden', noFilterOption: false);
        $this->enumFilter($table, 'type_maintenance', __('Type maintenance'), MaintenanceTypeMaintenance::class);
        $this->booleanFilter($table, 'apk', __('MOT'));
        $this->enumFilter($table, 'payment_method', __('Payment method'), MaintenancePaymentMethod::class);
    }

    private function isSmallCheck(Maintenance $maintenance): bool
    {
        return in_array($maintenance->type_maintenance, self::CHECKS, true);
    }
}
