<?php

declare(strict_types=1);

namespace App\Forms;

use App\Forms\Fields\Heading;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Textarea;

class VignetteForm extends VehicleRecordForm
{
    protected function heading(): string
    {
        return __('Vignette');
    }

    public function fields(): array
    {
        return [
            Heading::make($this->heading()),
            $this->money('price', __('Price'), required: true),

            Heading::make(__('Validity')),
            $this->date('start_date', __('Start date'), required: true),
            $this->date('end_date', __('End date'))
                ->rules('after_or_equal:start_date'),
            $this->country(),
            Textarea::make('areas')
                ->label(__('Areas'))
                ->rules(['nullable', 'string']),

            Heading::make(__('Other')),
            Textarea::make('comments')
                ->label(__('Comments'))
                ->rules(['nullable', 'string']),

            Submit::make()->label(__('Save')),
        ];
    }
}
