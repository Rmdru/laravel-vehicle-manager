<?php

declare(strict_types=1);

namespace App\Forms;

use App\Forms\Fields\Heading;
use App\Traits\InsuranceTypeOptions;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;

class InsuranceForm extends VehicleRecordForm
{
    use InsuranceTypeOptions;

    public function fields(): array
    {
        return [
            Heading::make(__('Basic')),
            Text::make('insurance_company')
                ->label(__('Insurance company'))
                ->required()
                ->maxLength(50),
            $this->choice('type', __('Type'), $this->getInsuranceTypeOptions(), required: true),

            Heading::make(__('Payment')),
            $this->money('price', __('Price per month'), required: true),
            Number::make('invoice_day')
                ->label(__('Invoice day'))
                ->append(__('th of the month'))
                ->minValue(1)
                ->maxValue(31)
                ->rules(['required', 'integer']),

            Heading::make(__('Period')),
            $this->date('start_date', __('Start date'), required: true),
            $this->date('end_date', __('End date'))
                ->rules('after_or_equal:start_date'),

            Submit::make()->label(__('Save')),
        ];
    }
}
