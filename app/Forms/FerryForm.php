<?php

declare(strict_types=1);

namespace App\Forms;

use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;

class FerryForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            Text::make('start_location')
                ->label(__('Start location'))
                ->required()
                ->maxLength(100),
            Text::make('end_location')
                ->label(__('End location'))
                ->required()
                ->maxLength(100),
            $this->dateTime('start_date', __('Start date'), required: true)
                ->rules('before_or_equal:now'),
            $this->dateTime('end_date', __('End date'), required: true)
                ->rules('after_or_equal:start_date'),
            $this->money('price', __('Price'), required: true),

            Submit::make()->label(__('Save')),
        ];
    }
}
