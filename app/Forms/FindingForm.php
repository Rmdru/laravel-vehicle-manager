<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\FindingSeverity;
use App\Enums\FindingSource;
use App\Enums\FindingStatus;
use App\Services\MaintenancePlanService;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;
use TranquilTools\FormBuilder\Fields\Toggle;

class FindingForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            $this->choice('source', __('Source'), $this->enumOptions(FindingSource::class), required: true)
                ->default(FindingSource::GarageAdvice->value),
            Text::make('title')
                ->label(__('What'))
                ->required()
                ->maxLength(255),
            $this->choice('severity', __('Urgency'), $this->enumOptions(FindingSeverity::class), required: true)
                ->default(FindingSeverity::Soon->value),
            $this->date('date', __('Date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),
            Number::make('mileage')
                ->label(__('Mileage'))
                ->append('km')
                ->default($this->vehicle()?->mileage_latest)
                ->rules(['nullable', 'integer', 'min:0']),
            Text::make('garage')
                ->label(__('Garage'))
                ->maxLength(100)
                ->rules(['nullable', 'string'])
                ->if("source === 'garage_advice'"),
            Textarea::make('description')
                ->label(__('Description'))
                ->help(__('For a complaint: what you notice, and when: cold or warm, at what speed, when braking, steering or accelerating.'))
                ->rules(['nullable', 'string', 'max:5000']),
            $this->money('estimated_cost', __('Estimated costs')),
            // What it is about, for the maintenance plan and the garage.
            Select::make('components')
                ->label(__('Components'))
                ->multiple()
                ->options(MaintenancePlanService::options($this->vehicle()))
                ->rules(['nullable', 'array']),
            Toggle::make('for_garage')
                ->label(__('On the list for the garage'))
                ->rules(['boolean']),
            $this->choice('status', __('Status'), $this->enumOptions(FindingStatus::class), required: true)
                ->default(FindingStatus::Open->value),

            Submit::make()->label(__('Save')),
        ];
    }
}
