<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\ReconditioningForm;
use App\Models\Reconditioning;
use App\Tables\ReconditioningsTable;

class ReconditioningController extends VehicleRecordController
{
    protected string $model = Reconditioning::class;

    protected string $form = ReconditioningForm::class;

    protected string $table = ReconditioningsTable::class;

    protected string $routeName = 'reconditionings';

    protected function title(): string
    {
        return __('Reconditioning & washing');
    }

    protected function singularTitle(): string
    {
        return __('Reconditioning');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of reconditioning and washing to get insight in thir costs. This category only includes costs incurred to clean, detail or restore the vehicle to its original optical condition.');
    }
}
