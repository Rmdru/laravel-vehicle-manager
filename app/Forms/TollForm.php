<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\RoadType;
use App\Enums\TollPaymentCircumstances;
use App\Enums\TollPaymentMethod;
use App\Enums\TollType;
use App\Forms\Fields\Heading;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;

class TollForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            Heading::make(__('Basic')),
            $this->date('start_date', __('Start date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),
            $this->date('end_date', __('End date'), notInFuture: true)
                ->rules('after_or_equal:start_date'),

            Heading::make(__('Location')),
            $this->choice('type', __('Type'), $this->enumOptions(TollType::class), required: true),
            Text::make('toll_company')
                ->label(__('Toll company'))
                ->maxLength(100)
                ->rules(['nullable', 'string']),
            $this->country()->rules('required_with:road'),
            $this->choice('road_type', __('Road type'), $this->enumOptions(RoadType::class))
                ->rules('required_with:road'),
            Text::make('road')
                ->label(__('Road'))
                ->help(__('Separate multiple roads with a comma'))
                ->rules(['nullable', 'string', 'max:255']),
            Text::make('start_location')
                ->label(__('Start location'))
                ->required()
                ->maxLength(100),
            Text::make('end_location')
                ->label(__('End location'))
                ->maxLength(100)
                ->rules(['nullable', 'string'])
                ->if("type === 'section'"),

            Heading::make(__('Payment')),
            $this->money('price', __('Price'), required: true),
            $this->choice('payment_circumstances', __('Payment circumstances'), $this->enumOptions(TollPaymentCircumstances::class)),
            $this->choice('payment_method', __('Payment method'), $this->enumOptions(TollPaymentMethod::class)),

            Submit::make()->label(__('Save')),
        ];
    }
}
