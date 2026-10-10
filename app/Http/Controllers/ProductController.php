<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\ProductForm;
use App\Models\Product;
use App\Tables\ProductsTable;

class ProductController extends VehicleRecordController
{
    protected string $model = Product::class;

    protected string $form = ProductForm::class;

    protected string $table = ProductsTable::class;

    protected string $routeName = 'products';

    protected function title(): string
    {
        return __('Products & accessory');
    }

    protected function singularTitle(): string
    {
        return __('Product');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of products such as engine fluids, practical accessory, interior products, cleaning products and safety equipment to get insight in their costs.');
    }
}
