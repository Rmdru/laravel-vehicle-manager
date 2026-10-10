<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\AccidentSituation;
use App\Enums\AccidentType;
use App\Models\Accident;
use TranquilTools\TableBuilder\TableBuilder;

class AccidentsTable extends VehicleRecordTable
{
    protected string $model = Accident::class;

    protected string $dateColumn = 'datetime';

    public function configure(TableBuilder $table): void
    {
        $table
            ->defaultSort('-datetime')
            ->withGlobalSearch(columns: ['location', 'description'])
            ->column('datetime', __('Date and time'), sortable: true, as: fn ($value, Accident $accident) => $this->withIcon('gmdi-calendar-month-r', $this->date($accident->datetime, 'DD-MM-YYYY HH:mm')))
            ->column('location', __('Location'), sortable: true, as: fn ($value) => $this->withIcon('gmdi-location-on-r', $value))
            ->column('type', __('Type'), sortable: true, as: fn ($value) => $this->enumBadge(AccidentType::class, $value))
            ->column('situation', __('Situation'), as: fn ($value, Accident $accident) => $this->enumBadge(AccidentSituation::class, $accident->situation))
            ->column('total_price', __('Total price'), sortable: true, as: fn ($value) => $this->withIcon('mdi-hand-coin-outline', $this->money($value)))
            ->paginate(25);

        $this->enumFilter($table, 'type', __('Type'), AccidentType::class);
        $this->enumFilter($table, 'situation', __('Situation'), AccidentSituation::class, json: true);
        $this->booleanFilter($table, 'guilty', __('Guilty'));
    }
}
