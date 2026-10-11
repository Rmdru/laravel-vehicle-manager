<?php

declare(strict_types=1);

namespace App\Tables;

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
            ->column('price', __('Price'), sortable: true, as: fn ($value) => $this->amount($this->money($value)))
            ->column('date', __('Date'), sortable: true, as: fn ($value, Product $product) => $this->text($this->date($product->date)))
            ->paginate(25);
    }
}
