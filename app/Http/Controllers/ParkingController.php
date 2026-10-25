<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\ParkingForm;
use App\Models\Parking;
use App\Tables\ParkingTable;

class ParkingController extends VehicleRecordController
{
    protected string $model = Parking::class;

    protected string $form = ParkingForm::class;

    protected string $table = ParkingTable::class;

    protected string $routeName = 'parking';

    protected string $dateAttribute = 'start_time';

    protected array $duplicateFields = ['start_time', 'end_time'];

    protected function title(): string
    {
        return __('Parking');
    }

    protected function singularTitle(): string
    {
        return __('Parking');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of parking to get insight in their costs. This category includes all parking fees charged per session. These fees are paid to a company or government institution.');
    }
}
