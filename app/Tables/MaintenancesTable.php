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

    /**
     * Without the checks of the maintenance plan (tire pressure, fluids):
     * they are ticked off there, and would crowd the list.
     */
    public function for(): Builder
    {
        return parent::for()->where(fn (Builder $query) => $query->whereNull('type_maintenance')->orWhere(fn (Builder $query) => collect(self::CHECKS)
            ->each(fn (string $check) => $query->whereJsonDoesntContain('type_maintenance', $check))));
    }

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['garage', 'description'])
            ->column('date', __('Date'), sortable: true, as: fn ($value, Maintenance $maintenance) => $this->text($this->date($maintenance->date)))
            ->column('garage', __('Garage'), sortable: true, as: fn ($value) => $this->text($value ?: __('Unknown')))
            ->column('type_maintenance', __('Type maintenance'), as: fn ($value, Maintenance $maintenance) => $this->enumBadge(MaintenanceTypeMaintenance::class, $maintenance->type_maintenance))
            ->column('apk', __('MOT'), sortable: true, as: fn ($value, Maintenance $maintenance) => new HtmlString(Html::badge($maintenance->apk ? __('MOT') : __('No MOT'))))
            ->column('mileage', __('Mileage'), sortable: true, as: fn ($value) => filled($value) ? $this->text($value.' km') : '')
            ->column('total_price', __('Total price'), sortable: true, as: fn ($value) => $this->amount($this->money($value) ?: __('Unknown')))
            ->paginate(25);

        $this->optionsFilter($table, 'type_maintenance', __('Type maintenance'), collect(MaintenanceTypeMaintenance::cases())
            ->reject(fn (MaintenanceTypeMaintenance $type) => in_array($type->value, self::CHECKS, true))
            ->mapWithKeys(fn (MaintenanceTypeMaintenance $type) => [$type->value => (string) $type->getLabel()])
            ->all(), json: true);
        $this->booleanFilter($table, 'apk', __('MOT'));
        $this->enumFilter($table, 'payment_method', __('Payment method'), MaintenancePaymentMethod::class);
    }
}
