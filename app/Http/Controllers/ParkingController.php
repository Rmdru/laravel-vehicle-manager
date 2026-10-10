<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\ParkingForm;
use App\Models\Parking;
use App\Tables\ParkingTable;
use Illuminate\Http\RedirectResponse;

class ParkingController extends VehicleRecordController
{
    protected string $model = Parking::class;

    protected string $form = ParkingForm::class;

    protected string $table = ParkingTable::class;

    protected string $routeName = 'parking';

    protected string $dateAttribute = 'start_time';

    public function duplicate(string $id): RedirectResponse
    {
        $replica = $this->record($id)->replicate();
        $replica->start_time = now();
        $replica->end_time = now()->addHour();
        $replica->save();

        return $this->redirectToIndex(__(':name duplicated.', ['name' => $this->singularTitle()]));
    }

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
