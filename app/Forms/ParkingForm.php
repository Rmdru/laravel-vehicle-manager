<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\ParkingPaymentMethod;
use App\Enums\ParkingType;
use App\Forms\Fields\Heading;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;

class ParkingForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            Heading::make(__('Basic')),
            $this->choice('type', __('Type'), $this->enumOptions(ParkingType::class), required: true),
            Text::make('location')
                ->label(__('Location'))
                ->required()
                ->maxLength(100),
            Text::make('company')
                ->label(__('Company'))
                ->maxLength(100)
                ->rules(['nullable', 'string']),

            Heading::make(__('Period')),
            $this->dateTime('start_time', __('Start time'), required: true)
                ->default(now()->format('Y-m-d H:i')),
            $this->dateTime('end_time', __('End time'), required: true)
                ->default(now()->format('Y-m-d H:i'))
                ->rules('after_or_equal:start_time'),

            Heading::make(__('Payment')),
            $this->money('price', __('Price'), required: true),
            $this->choice('payment_method', __('Payment method'), $this->enumOptions(ParkingPaymentMethod::class)),

            Submit::make()->label(__('Save')),
        ];
    }
}
