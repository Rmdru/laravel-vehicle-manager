<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\TaxForm;
use App\Models\Tax;
use App\Tables\TaxesTable;

class TaxController extends VehicleRecordController
{
    protected string $model = Tax::class;

    protected string $form = TaxForm::class;

    protected string $table = TaxesTable::class;

    protected string $routeName = 'taxes';

    protected string $dateAttribute = 'start_date';

    protected array $duplicateFields = ['start_date', 'end_date'];

    protected function title(): string
    {
        return __('Road taxes');
    }

    protected function singularTitle(): string
    {
        return __('Road tax');
    }

    protected function description(): string
    {
        return __('Here you can your road taxes to get insight of their costs. This category includes only periodic costs paid to the government for providing access to the road network and associated facilities.');
    }
}
