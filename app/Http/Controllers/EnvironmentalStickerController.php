<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\EnvironmentalStickerForm;
use App\Models\EnvironmentalSticker;
use App\Tables\EnvironmentalStickersTable;

class EnvironmentalStickerController extends VehicleRecordController
{
    protected string $model = EnvironmentalSticker::class;

    protected string $form = EnvironmentalStickerForm::class;

    protected string $table = EnvironmentalStickersTable::class;

    protected string $routeName = 'environmental-stickers';

    protected string $dateAttribute = 'start_date';

    protected function title(): string
    {
        return __('Environmental stickers');
    }

    protected function singularTitle(): string
    {
        return __('Environmental sticker');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of environmental stickers to get insight in their costs. This category includes the one-off costs for a environmental sticker that gives access to a specific area for a certain period.');
    }
}
