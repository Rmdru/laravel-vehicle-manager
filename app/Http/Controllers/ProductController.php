<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductCategory;
use App\Forms\ProductForm;
use App\Models\Product;
use App\Tables\ProductsTable;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Only a part replaces a component; what was chosen before the category changed is let go.
     */
    protected function prepare(array $data, ?Model $record): array
    {
        if (($data['category'] ?? null) !== ProductCategory::Part->value) {
            $data['component'] = null;
        }

        return $data;
    }

    protected function description(): string
    {
        return __('Here you can add the costs of products such as engine fluids, car parts you replace yourself, detailing products, practical accessories and safety equipment to get insight in their costs.');
    }
}
