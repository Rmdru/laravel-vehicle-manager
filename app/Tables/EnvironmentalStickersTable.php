<?php

declare(strict_types=1);

namespace App\Tables;

use App\Models\EnvironmentalSticker;

class EnvironmentalStickersTable extends VignettesTable
{
    protected string $model = EnvironmentalSticker::class;
}
