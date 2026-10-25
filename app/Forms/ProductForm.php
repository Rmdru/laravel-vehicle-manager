<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\ProductCategory;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;

class ProductForm extends VehicleRecordForm
{
    public function fields(): array
    {
        $fluids = collect(ProductCategory::FLUIDS)->map(fn (ProductCategory $category) => "'{$category->value}'")->implode(', ');

        return [
            Text::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            $this->choice('category', __('Category'), $this->enumOptions(ProductCategory::class)),
            // A fluid topped up tells about the engine: how much, and at what mileage.
            Number::make('quantity')
                ->label(__('Quantity'))
                ->append('l')
                ->step(0.01)
                ->rules(['nullable', 'numeric', 'min:0', 'max:1000'])
                ->if("[{$fluids}].includes(category)"),
            Number::make('mileage')
                ->label(__('Topped up at'))
                ->append('km')
                ->help(__('Fill in the mileage when you topped up with it: how much oil or coolant the engine uses tells about its state.'))
                ->rules(['nullable', 'integer', 'min:0'])
                ->if("[{$fluids}].includes(category)"),
            $this->money('price', __('Price'))
                ->help(__('Empty when you topped up from a bottle you bought before.')),
            $this->date('date', __('Date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),

            Submit::make()->label(__('Save')),
        ];
    }
}
