<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\InsuranceForm;
use App\Models\Insurance;
use App\Tables\InsurancesTable;

class InsuranceController extends VehicleRecordController
{
    protected string $model = Insurance::class;

    protected string $form = InsuranceForm::class;

    protected string $table = InsurancesTable::class;

    protected string $routeName = 'insurances';

    protected string $dateAttribute = 'start_date';

    protected array $duplicateFields = ['price', 'start_date', 'end_date'];

    protected function title(): string
    {
        return __('Insurances');
    }

    protected function singularTitle(): string
    {
        return __('Insurance');
    }

    protected function description(): string
    {
        return __('Here you can your insurances to get insight of their costs. This category only includes periodic premium costs to protect against unexpectedly high costs in the event of accidents and damage.');
    }
}
