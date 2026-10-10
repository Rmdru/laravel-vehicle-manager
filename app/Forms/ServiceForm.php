<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\ServiceType;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;

class ServiceForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            $this->choice('type', __('Type'), $this->enumOptions(ServiceType::class), required: true),
            Text::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            $this->money('price', __('Price'), required: true),
            $this->date('date', __('Date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),
            Textarea::make('comments')
                ->label(__('Comments'))
                ->rules(['nullable', 'string']),

            Submit::make()->label(__('Save')),
        ];
    }
}
