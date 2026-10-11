<?php

declare(strict_types=1);

namespace App\Tables;

use App\Enums\RefuelingDrivingStyle;
use App\Enums\RefuelingPaymentMethod;
use App\Enums\RefuelingRoutes;
use App\Enums\RefuelingTires;
use App\Models\Refueling;
use App\Support\CurrentVehicle;
use App\Support\Html;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use TranquilTools\TableBuilder\TableBuilder;

class RefuelingsTable extends VehicleRecordTable
{
    protected string $model = Refueling::class;

    /**
     * Filter on the brand of the gas station: the first word of its name.
     */
    private function gasStationFilter(TableBuilder $table): void
    {
        $brands = $this->usedValues('gas_station')
            ->map(fn (string $name) => (string) str($name)->lower()->explode(' ')->first())
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $brand) => [$brand => (string) str($brand)->ucfirst()])
            ->all();

        $this->filter($table, 'gas_station', __('Gas station'), $brands, fn (Builder $query, string $brand) => $query
            ->where(fn (Builder $query) => $query->where('gas_station', $brand)->orWhere('gas_station', 'like', $brand.' %')));
    }

    public function configure(TableBuilder $table): void
    {
        $vehicle = CurrentVehicle::get();
        $logos = config('refuelings.gas_station_logos');
        $fuelTypes = trans('fuel_types');
        $unit = trans('powertrains')[$vehicle?->powertrain]['consumption_unit'] ?? 'l/100km';
        $average = round((float) Refueling::where('vehicle_id', $vehicle?->id)->avg('fuel_consumption'), 2);

        $table
            ->defaultSort('-date')
            ->withGlobalSearch(columns: ['gas_station'])
            ->column('logo', '', canBeHidden: false, as: function ($value, Refueling $refueling) use ($logos) {
                $brand = (string) str($refueling->gas_station)->lower()->explode(' ')->first();

                return new HtmlString(Html::logo($logos[$brand] ?? $logos['default']));
            })
            ->column('date', __('Date'), sortable: true, as: fn ($value, Refueling $refueling) => $this->text($this->date($refueling->date)))
            ->column('gas_station', __('Gas station'), sortable: true, as: fn ($value) => $this->text($value))
            ->column('total_price', __('Total price'), sortable: true, as: fn ($value) => $this->amount($this->money($value)))
            ->column('fuel_consumption', __('Fuel consumption'), sortable: true, as: function ($value) use ($average, $unit) {
                [$color, $icon] = match (true) {
                    $value > $average => ['danger', 'ic:round-trending-up'],
                    $value < $average => ['success', 'ic:round-trending-down'],
                    default => ['warning', 'mdi:approximately-equal'],
                };

                return new HtmlString(Html::badge(number_format((float) $value, 2, ',', '.').' '.$unit, $color, $icon));
            })
            ->column('fuel_type', __('Fuel type'), sortable: true, as: fn ($value) => new HtmlString(Html::badge($fuelTypes[$value] ?? (string) $value, icon: 'ic:round-local-gas-station')))
            ->paginate(25);

        $this->optionsFilter($table, 'fuel_type', __('Fuel type'), $fuelTypes);
        $this->countryFilter($table);
        $this->gasStationFilter($table);
        $this->enumFilter($table, 'payment_method', __('Payment method'), RefuelingPaymentMethod::class);
        $this->enumFilter($table, 'tires', __('Tires'), RefuelingTires::class);
        $this->enumFilter($table, 'driving_style', __('Driving style'), RefuelingDrivingStyle::class);
        $this->enumFilter($table, 'routes', __('Routes'), RefuelingRoutes::class, json: true);
    }
}
