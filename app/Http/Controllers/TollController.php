<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\TollForm;
use App\Models\Toll;
use App\Tables\TollTable;
use Illuminate\Database\Eloquent\Model;

class TollController extends VehicleRecordController
{
    protected string $model = Toll::class;

    protected string $form = TollForm::class;

    protected string $table = TollTable::class;

    protected string $routeName = 'toll';

    protected string $dateAttribute = 'start_date';

    protected function prepare(array $data, ?Model $record): array
    {
        // Roads are entered comma separated and stored as a list.
        $data['road'] = str($data['road'] ?? '')
            ->explode(',')
            ->map(fn (string $road) => trim($road))
            ->filter()
            ->values()
            ->all() ?: null;

        return $data;
    }

    protected function formData(Model $record): array
    {
        return [...parent::formData($record), 'road' => implode(', ', (array) $record->getAttribute('road'))];
    }

    protected function title(): string
    {
        return __('Toll');
    }

    protected function singularTitle(): string
    {
        return __('Toll');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of toll sessions to get insight in their costs. This category includes all toll session fees. These fees are paid when the vehicle drives through a specific road section. These fees are paid to the road authority or toll company, usually a company or government agency.');
    }
}
