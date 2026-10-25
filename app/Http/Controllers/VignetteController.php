<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\VignetteForm;
use App\Models\Vignette;
use App\Tables\VignettesTable;

class VignetteController extends VehicleRecordController
{
    protected string $model = Vignette::class;

    protected string $form = VignetteForm::class;

    protected string $table = VignettesTable::class;

    protected string $routeName = 'vignettes';

    protected string $dateAttribute = 'start_date';

    protected array $duplicateFields = ['start_date', 'end_date'];

    protected function title(): string
    {
        return __('Vignettes');
    }

    protected function singularTitle(): string
    {
        return __('Vignette');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of vignettes to get insight in their costs. This category includes the one-off costs for a vignette that gives access to a specific area for a certain period.');
    }
}
