<?php

declare(strict_types=1);

namespace App\Forms;

use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Submit;

class TaxForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            $this->date('start_date', __('Start date'), required: true, notInFuture: true),
            $this->date('end_date', __('End date'))
                ->rules('after_or_equal:start_date'),
            $this->money('price', __('Price per month'), required: true),
            Number::make('invoice_day')
                ->label(__('Invoice day'))
                ->append(__('th of the month'))
                ->minValue(1)
                ->maxValue(31)
                ->rules(['required', 'integer']),

            Submit::make()->label(__('Save')),
        ];
    }
}
