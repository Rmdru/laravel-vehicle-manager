<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\ReconditioningExecutor;
use App\Enums\ReconditioningType;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;

class ReconditioningForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            $this->date('date', __('Date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),
            $this->choice('type', __('Type'), $this->enumOptions(ReconditioningType::class), required: true),
            $this->choice('executor', __('Executor'), $this->enumOptions(ReconditioningExecutor::class), required: true),
            $this->money('price', __('Price')),
            Text::make('location')
                ->label(__('Location'))
                ->maxLength(100)
                ->rules(['nullable', 'string']),
            Textarea::make('description')
                ->label(__('Description'))
                ->rules(['nullable', 'string']),

            Submit::make()->label(__('Save')),
        ];
    }
}
