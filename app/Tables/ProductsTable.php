<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\ProductCategory;
use App\Models\Product;
use TranquilTools\TableBuilder\TableBuilder;

class ProductsTable extends VehicleRecordTable
{
    protected string $model = Product::class;

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['name'])
            ->column('name', __('Name'), sortable: true)
            ->column('category', __('Category'), sortable: true, as: fn ($value, Product $product) => $this->enumBadge(ProductCategory::class, $product->category?->value))
            // A top-up with the mileage it was done at.
            ->column('quantity', __('Quantity'), sortable: true, as: fn ($value, Product $product) => $product->quantity
                ? $this->text(number_format($product->quantity, 2, ',', '.').' l'.($product->mileage ? ' · '.number_format($product->mileage, 0, ',', '.').' km' : ''))
                : '')
            ->column('price', __('Price'), sortable: true, as: fn ($value) => filled($value) ? $this->amount($this->money($value)) : '')
            ->column('date', __('Date'), sortable: true, as: fn ($value, Product $product) => $this->text($this->date($product->date)))
            ->paginate(25);

        $this->enumFilter($table, 'category', __('Category'), ProductCategory::class);
    }
}
