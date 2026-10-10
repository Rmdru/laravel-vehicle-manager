<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Forms\ServiceForm;
use App\Models\Service;
use App\Tables\ServicesTable;

class ServiceController extends VehicleRecordController
{
    protected string $model = Service::class;

    protected string $form = ServiceForm::class;

    protected string $table = ServicesTable::class;

    protected string $routeName = 'services';

    protected function title(): string
    {
        return __('Services');
    }

    protected function singularTitle(): string
    {
        return __('Service');
    }

    protected function description(): string
    {
        return __('Here you can add the cost of services you used en route. These services can be facilitated so that the driver can transport the passengers safely and comfortably.');
    }
}
