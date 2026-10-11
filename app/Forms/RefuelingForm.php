<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\RefuelingClimateControl;
use App\Enums\RefuelingDrivingStyle;
use App\Enums\RefuelingPaymentMethod;
use App\Enums\RefuelingRoutes;
use App\Enums\RefuelingTires;
use App\Forms\Fields\Heading;
use App\Models\Refueling;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use TranquilTools\FormBuilder\Fields\Checkbox;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;
use TranquilTools\FormBuilder\Fields\Time;

class RefuelingForm extends VehicleRecordForm
{
    private const IS_ELECTRIC = "String(fuel_type || '').toLowerCase().includes('electric')";

    public function fields(): array
    {
        $vehicle = $this->vehicle();
        $powertrain = $this->powertrain();
        $unit = $powertrain['unit_short'] ?? 'l';
        $consumptionUnit = $powertrain['consumption_unit'] ?? 'l/100km';
        $fuelTypes = $this->fuelTypeOptions();

        $previous = $vehicle
            ? Refueling::query()->where('vehicle_id', $vehicle->id)->newest()->first()
            : null;

        return [
            Heading::make(__('Refueling')),
            $this->date('date', __('Date'), required: true, notInFuture: true)
                ->default(today()->format('Y-m-d')),
            $this->country(),
            Text::make('gas_station')
                ->label(__('Gas station'))
                ->required()
                ->maxLength(100)
                ->help(__('The first word is used for the brand logo (if available)')),

            Heading::make(__('Fuel')),
            Select::make('fuel_type')
                ->label(__('Fuel type'))
                ->options($fuelTypes)
                ->rules(['required', Rule::in(array_keys($fuelTypes))]),
            Number::make('amount')
                ->label(__('Amount'))
                ->append($unit)
                ->step(0.01)
                ->rules(['required', 'numeric', 'gt:0']),
            Number::make('percentage')
                ->label(__('Tank percentage after refueling'))
                ->append('%')
                ->step(0.01)
                ->default(100)
                ->minValue(0)
                ->maxValue(100)
                ->rules(['required', 'numeric'])
                ->help(__('Default value is 100%')),
            $this->money('unit_price', __('Unit price'), required: true, step: 0.001)
                ->append('/'.$unit),
            $this->money('total_price', __('Total price'), required: true),
            Time::make('charge_time')
                ->label(__('Charge time'))
                ->step(5)
                ->if(self::IS_ELECTRIC),
            Checkbox::make('service_by_attendant')
                ->label(__('Service by attendant'))
                ->rules(['boolean'])
                ->if('!'.self::IS_ELECTRIC),

            Heading::make(__('Car')),
            Number::make('mileage_begin')
                ->label(__('Mileage begin'))
                ->append('km')
                ->default($vehicle?->mileage_latest)
                ->rules(['required', 'integer', 'min:0']),
            Number::make('mileage_end')
                ->label(__('Mileage end'))
                ->append('km')
                ->rules(['required', 'integer', 'gt:mileage_begin']),
            Number::make('fuel_consumption_onboard_computer')
                ->label(__('Fuel consumption onboard computer'))
                ->append($consumptionUnit)
                ->step(0.01)
                ->rules(['nullable', 'numeric', 'min:0']),
            Number::make('avg_speed')
                ->label(__('Average speed'))
                ->append('km/h')
                ->step(0.1)
                ->rules(['nullable', 'numeric', 'min:0']),

            Heading::make(__('Circumstances')),
            $this->choice('tires', __('Tires'), $this->enumOptions(RefuelingTires::class))
                ->default($previous?->tires),
            $this->choices('climate_control', __('Climate control'), RefuelingClimateControl::class)
                ->default($previous?->climate_control),
            $this->choices('routes', __('Routes'), RefuelingRoutes::class)
                ->default($previous?->routes),
            $this->choice('driving_style', __('Driving style'), $this->enumOptions(RefuelingDrivingStyle::class))
                ->default($previous?->driving_style),
            Textarea::make('comments')
                ->label(__('Comments'))
                ->rules(['nullable', 'string']),

            Heading::make(__('Payment')),
            $this->choice('payment_method', __('Payment method'), $this->enumOptions(RefuelingPaymentMethod::class)),
            Text::make('discount')
                ->label(__('Discount'))
                ->rules(['nullable', 'string', 'max:255']),

            Submit::make()->label(__('Save')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fuelTypeOptions(): array
    {
        $fuelTypes = trans('fuel_types');
        $compatible = Arr::only($fuelTypes, $this->vehicle()?->fuel_types ?? []);

        return $compatible ?: $fuelTypes;
    }
}
