<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\FinePaymentMethod;
use App\Enums\FineProvider;
use App\Enums\FineSanction;
use App\Enums\FineType;
use App\Enums\RoadType;
use App\Forms\Fields\Heading;
use TranquilTools\FormBuilder\Fields\Checkbox;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;
use TranquilTools\FormBuilder\Fields\Toggle;

class FineForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            Heading::make(__('Basic')),
            $this->choice('type', __('Type'), $this->enumOptions(FineType::class), required: true),
            $this->date('date', __('Date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),
            $this->choice('provider', __('Provider'), $this->enumOptions(FineProvider::class), required: true),

            Heading::make(__('Fact')),
            Text::make('fact')
                ->label(__('Fact'))
                ->required()
                ->maxLength(100),
            Textarea::make('description')
                ->label(__('Description'))
                ->rules(['nullable', 'string']),

            Heading::make(__('Location')),
            $this->country()->rules('required_with:road'),
            Text::make('location')
                ->label(__('Location'))
                ->maxLength(100)
                ->rules(['nullable', 'string']),
            Text::make('road')
                ->label(__('Road'))
                ->maxLength(100)
                ->rules(['nullable', 'string']),
            $this->choice('road_type', __('Road type'), $this->enumOptions(RoadType::class))
                ->rules('required_with:road'),
            Number::make('road_distance_marker')
                ->label(__('Road distance marker'))
                ->append('km')
                ->step(0.01)
                ->rules(['nullable', 'numeric', 'min:0']),

            Heading::make(__('Fine')),
            Toggle::make('fine')
                ->label(__('Fine'))
                ->rules(['boolean']),
            Checkbox::make('payed')
                ->label(__('Payed'))
                ->rules(['boolean']),
            $this->money('price', __('Price')),
            $this->date('payment_date', __('Payment date'), notInFuture: true),
            $this->choice('payment_method', __('Payment method'), $this->enumOptions(FinePaymentMethod::class)),

            Heading::make(__('Sanctions')),
            $this->choices('sanctions', __('Sanctions'), $this->enumOptions(FineSanction::class)),

            Submit::make()->label(__('Save')),
        ];
    }
}
