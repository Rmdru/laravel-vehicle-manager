<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccidentSituation;
use App\Enums\VehicleStatus;
use App\Forms\AccidentForm;
use App\Models\Accident;
use App\Tables\AccidentsTable;
use Illuminate\Database\Eloquent\Model;

class AccidentController extends VehicleRecordController
{
    protected string $model = Accident::class;

    protected string $form = AccidentForm::class;

    protected string $table = AccidentsTable::class;

    protected string $routeName = 'accidents';

    protected string $dateAttribute = 'datetime';

    protected ?string $priceColumn = 'total_price';

    protected function prepare(array $data, ?Model $record): array
    {
        $data['total_price'] = ((float) ($data['damage_own'] ?? 0) + (float) ($data['damage_others'] ?? 0))
            - ((float) ($data['damage_own_insured'] ?? 0) + (float) ($data['damage_others_insured'] ?? 0));

        // The description is optional in the form, but the column does not allow null.
        $data['description'] ??= '';

        return $data;
    }

    protected function saved(Model $record): void
    {
        if (in_array(AccidentSituation::VehicleNotRollable->value, (array) $record->situation, true)) {
            $this->vehicle()->update(['status' => VehicleStatus::NotRollable->value]);
        }
    }

    protected function title(): string
    {
        return __('Accidents & damage');
    }

    protected function singularTitle(): string
    {
        return __('Accident');
    }

    protected function description(): string
    {
        return __('Here you can add accidents and damages. You can add all costs of damage on you, your objects, your occupants and the vehicle as well as other involved vehicles, peoples, animals and objects.');
    }
}
