<?php

declare(strict_types=1);

namespace App\Tables;

use App\Models\Vignette;
use App\Support\Html;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\TableBuilder;

class VignettesTable extends VehicleRecordTable
{
    protected string $model = Vignette::class;

    protected string $dateColumn = 'start_date';

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-start_date')
            ->withGlobalSearch(columns: ['areas', 'comments'])
            ->column('country', __('Country'), sortable: true, as: fn ($value) => new HtmlString(Html::flag($value, showName: true)))
            ->column('start_date', __('Start date'), sortable: true, as: fn ($value, Model $record) => $this->text($this->period($record->getAttribute('start_date'), $record->getAttribute('end_date'), openEnd: __('forever'))))
            ->column('price', __('Price'), sortable: true, as: fn ($value) => $this->amount($this->money($value)))
            ->column('areas', __('Areas'), as: fn ($value) => $this->text((string) str((string) $value)->limit(80)))
            ->column('comments', __('Comments'), as: fn ($value) => $this->text((string) str((string) $value)->limit(80)))
            ->paginate(25);

        $this->countryFilter($table);
        $this->activeFilter($table);
    }
}
