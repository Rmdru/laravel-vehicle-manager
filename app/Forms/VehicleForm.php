<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\VehicleStatus;
use App\Forms\Fields\Heading;
use App\Models\Vehicle;
use App\Support\StatusNotification;
use App\Traits\PowerTrainOptions;
use Illuminate\Validation\Rule;
use TranquilTools\FormBuilder\Fields\Checkbox;
use TranquilTools\FormBuilder\Fields\File;
use TranquilTools\FormBuilder\Fields\Hidden;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Repeater;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;

class VehicleForm extends VehicleRecordForm
{
    use PowerTrainOptions;

    public const NOTIFICATION_PREFIX = 'notifications__';

    public function fields(): array
    {
        $brands = config('vehicles.brands');
        $powertrains = $this->getPowerTrainOptions();
        $fuelTypes = trans('fuel_types');
        $countries = $this->getCountryOptions();

        return [
            Heading::make(__('License plate')),
            Text::make('license_plate')
                ->label(__('License plate'))
                ->required()
                ->maxLength(20)
                ->attributes(['fullWidth' => true])
                ->help(__('When you complete typing, some fields will be automatically filled in based on data from the RDW (only for vehicles registered in the Netherlands).')),

            Heading::make(__('Basic')),
            Select::make('brand')
                ->label(__('Brand'))
                ->options(collect($brands)->map(fn (string $name, int $key) => ['value' => $key, 'label' => $name])->values())
                ->searchable()
                ->rules(['required', Rule::in(array_keys($brands))]),
            Text::make('model')
                ->label(__('Model'))
                ->required()
                ->maxLength(50),
            Text::make('version')
                ->label(__('Version'))
                ->required()
                ->maxLength(50),
            Text::make('engine')
                ->label(__('Engine'))
                ->maxLength(50)
                ->rules(['nullable', 'string']),
            Select::make('powertrain')
                ->label(__('Powertrain'))
                ->options($powertrains)
                ->searchable()
                ->rules(['required', Rule::in(array_keys($powertrains))]),
            Select::make('fuel_types')
                ->label(__('Compatible fuel types'))
                ->options($fuelTypes)
                ->multiple()
                ->rules(['required', 'array', 'min:1']),
            Number::make('tank_capacity')
                ->label(__('Tank capacity'))
                ->step(0.1)
                ->rules(['required', 'numeric', 'min:0']),
            // Such as an inspection service and an oil service, each restarted by its own maintenance types.
            Repeater::make('maintenance_intervals')
                ->label(__('Maintenance intervals'))
                ->addActionLabel(__('Add interval'))
                ->default([['types' => Vehicle::MAINTENANCE_TYPES, 'km' => 15000, 'months' => 12]])
                ->fields([
                    Select::make('types')
                        ->label(__('Type maintenance'))
                        ->multiple()
                        ->options(Vehicle::intervalTypeOptions())
                        ->rules(['required', 'array', 'min:1']),
                    Number::make('km')
                        ->label(__('Maintenance interval distance'))
                        ->prepend(__('Every'))
                        ->append('km')
                        ->minValue(1)
                        ->rules(['nullable', 'integer', 'min:1', 'required_without:maintenance_intervals.*.months']),
                    Number::make('months')
                        ->label(__('Maintenance interval time'))
                        ->prepend(__('Every'))
                        ->append(__('months'))
                        ->minValue(1)
                        ->rules(['nullable', 'integer', 'min:1']),
                ])
                ->rules(['nullable', 'array']),
            File::make('image')
                ->label(__('Image'))
                ->accept('image/jpeg', 'image/png', 'image/gif', 'image/webp')
                ->rules(['nullable', 'max:10240']),

            Heading::make(__('Ownership')),
            Number::make('mileage_start')
                ->label(__('Mileage on purchase'))
                ->append('km')
                ->rules(['nullable', 'integer', 'min:0']),
            $this->date('purchase_date', __('Purchase date'), notInFuture: true),
            $this->date('construction_date', __('Construction date'), required: true, notInFuture: true),
            $this->money('purchase_price', __('Purchase price')),
            Select::make('country_registration')
                ->label(__('Country of registration'))
                ->options($countries)
                ->searchable()
                ->help(__('Is used for the license plate layout'))
                ->rules(['required', Rule::in(array_keys($countries))]),
            $this->choice('status', __('Status'), $this->enumOptions(VehicleStatus::class), required: true)
                ->default(VehicleStatus::Drivable->value),

            Heading::make(__('Specifications')),
            Repeater::make('specifications')
                ->addActionLabel(__('Add'))
                ->fields([
                    Text::make('name')
                        ->label(__('Name'))
                        ->rules(['nullable', 'string', 'max:255']),
                    Text::make('value')
                        ->label(__('Value'))
                        ->rules(['nullable', 'max:255']),
                    Hidden::make('icon'),
                ])
                ->rules(['nullable']),

            Heading::make(__('Notifications')),
            ...$this->notificationFields(),

            Submit::make()->label(__('Save')),
        ];
    }

    /**
     * One checkbox per status notification, named "notifications__{category}__{key}".
     *
     * @return list<Checkbox>
     */
    private function notificationFields(): array
    {
        return collect(StatusNotification::configuration())
            ->map(fn (array $notification, string $key) => Checkbox::make(self::notificationField($notification['category'], $key))
                ->label($notification['label'])
                ->checked()
                ->rules(['boolean']))
            ->values()
            ->all();
    }

    public static function notificationField(string $category, string $key): string
    {
        return self::NOTIFICATION_PREFIX.$category.'__'.$key;
    }
}
