<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\MaintenancePaymentMethod;
use App\Enums\MaintenanceTypeMaintenance;
use App\Forms\Fields\Heading;
use App\Traits\MaintenanceTypeOptions;
use TranquilTools\FormBuilder\Fields\Hidden;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Repeater;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;
use TranquilTools\FormBuilder\Fields\Toggle;

class MaintenanceForm extends VehicleRecordForm
{
    use MaintenanceTypeOptions;

    public function fields(): array
    {
        return [
            Heading::make(__('Maintenance')),
            $this->date('date', __('Date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),
            Text::make('garage')
                ->label(__('Garage'))
                ->maxLength(100)
                ->rules(['nullable', 'string']),
            Number::make('mileage')
                ->label(__('Mileage'))
                ->append('km')
                ->default($this->vehicle()?->mileage_latest)
                ->rules(['required', 'integer', 'min:0']),

            Heading::make(__('Tasks')),
            $this->choice('type_maintenance', __('Type maintenance'), $this->getMaintenanceTypeOptions([
                MaintenanceTypeMaintenance::Maintenance,
                MaintenanceTypeMaintenance::SmallMaintenance,
                MaintenanceTypeMaintenance::BigMaintenance,
            ])),
            Toggle::make('apk')
                ->label(__('MOT'))
                ->rules(['boolean']),
            $this->date('apk_date', __('MOT date')),
            Toggle::make('airco_check')
                ->label(__('Airco check'))
                ->rules(['boolean']),
            Textarea::make('description')
                ->label(__('Description'))
                ->rules(['nullable', 'string']),
            $this->money('total_price', __('Total price'), required: true),
            $this->choice('payment_method', __('Payment method'), $this->enumOptions(MaintenancePaymentMethod::class)),
            Repeater::make('tasks')
                ->label(__('Tasks'))
                ->addActionLabel(__('Add task'))
                ->fields([
                    Text::make('task')
                        ->label(__('Task'))
                        ->rules(['nullable', 'string', 'max:255']),
                    $this->money('price', __('Price')),
                    Hidden::make('icon'),
                ])
                ->rules(['nullable']),

            Submit::make()->label(__('Save')),
        ];
    }
}
