<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\FineForm;
use App\Models\Fine;
use App\Tables\FinesTable;

class FineController extends VehicleRecordController
{
    protected string $model = Fine::class;

    protected string $form = FineForm::class;

    protected string $table = FinesTable::class;

    protected string $routeName = 'fines';

    protected function title(): string
    {
        return __('Fines');
    }

    protected function singularTitle(): string
    {
        return __('Fine');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of fines to get insight in their costs. This category only includes the costs resulting from violations committed. Other sanctions may also be recorded.');
    }
}
