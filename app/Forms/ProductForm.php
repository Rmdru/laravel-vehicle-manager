<?php

declare(strict_types=1);

namespace App\Forms;

use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;

class ProductForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            Text::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            $this->money('price', __('Price'), required: true),
            $this->date('date', __('Date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),

            Submit::make()->label(__('Save')),
        ];
    }
}
