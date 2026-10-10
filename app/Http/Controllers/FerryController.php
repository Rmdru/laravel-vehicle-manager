<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\FerryForm;
use App\Models\Ferry;
use App\Tables\FerriesTable;

class FerryController extends VehicleRecordController
{
    protected string $model = Ferry::class;

    protected string $form = FerryForm::class;

    protected string $table = FerriesTable::class;

    protected string $routeName = 'ferries';

    protected string $dateAttribute = 'start_date';

    protected function title(): string
    {
        return __('Transport trains & ferry services');
    }

    protected function singularTitle(): string
    {
        return __('Transport train & ferry service');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of addional transport methods such as ferries and transportation trains to get insight in their costs.');
    }
}
