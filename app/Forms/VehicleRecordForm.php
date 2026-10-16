<?php

declare(strict_types=1);

namespace App\Forms;

use App\Contracts\HasIcon;
use App\Contracts\HasLabel;
use App\Models\Vehicle;
use App\Support\CurrentVehicle;
use App\Traits\CountryOptions;
use Illuminate\Validation\Rule;
use TranquilTools\FormBuilder\AbstractForm;
use TranquilTools\FormBuilder\Fields\Checkboxes;
use TranquilTools\FormBuilder\Fields\Date;
use TranquilTools\FormBuilder\Fields\DateTime;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Radio;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\FormConfig;

/**
 * Base for the forms of records that belong to the current vehicle.
 */
abstract class VehicleRecordForm extends AbstractForm
{
    use CountryOptions;

    public function configure(FormConfig $form): void
    {
        $form->class('space-y-4');
    }

    protected function vehicle(): ?Vehicle
    {
        return CurrentVehicle::get();
    }

    /**
     * @return array<string, string>
     */
    protected function powertrain(): array
    {
        return trans('powertrains')[$this->vehicle()?->powertrain] ?? [];
    }

    /**
     * @param  class-string<\BackedEnum&HasLabel>  $enum
     * @return array<string, string>
     */
    protected function enumOptions(string $enum): array
    {
        return collect($enum::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
            ->all();
    }

    protected function money(string $name, string $label, bool $required = false, float $step = 0.01): Number
    {
        return Number::make($name)
            ->label($label)
            ->prepend('€')
            ->step($step)
            ->rules([$required ? 'required' : 'nullable', 'numeric', 'min:0']);
    }

    protected function date(string $name, string $label, bool $required = false, bool $notInFuture = false): Date
    {
        $field = Date::make($name)
            ->label($label)
            ->format('DD-MM-YYYY')
            ->weekStartsOn(1)
            ->rules([$required ? 'required' : 'nullable', 'date']);

        if ($notInFuture) {
            $field->maxDate(today()->format('Y-m-d'))->rules('before_or_equal:today');
        }

        return $field;
    }

    protected function dateTime(string $name, string $label, bool $required = false): DateTime
    {
        return DateTime::make($name)
            ->label($label)
            ->format('DD-MM-YYYY HH:mm')
            ->weekStartsOn(1)
            ->rules([$required ? 'required' : 'nullable', 'date']);
    }

    /**
     * Single choice: radio buttons when required, a clearable select otherwise.
     *
     * @param  array<string, string>  $options
     */
    protected function choice(string $name, string $label, array $options, bool $required = false): Radio|Select
    {
        $field = $required
            ? Radio::make($name)->inline()
            : Select::make($name)->attributes([
                'clearable' => true,
                'clearLabel' => trans('form-builder::fields.clear'),
            ]);

        return $field
            ->label($label)
            ->options($options)
            ->rules([$required ? 'required' : 'nullable', Rule::in(array_keys($options))]);
    }

    /**
     * Multiple choice: a button per case of the enum, with its icon.
     *
     * @param  class-string<\BackedEnum&HasLabel>  $enum
     */
    protected function choices(string $name, string $label, string $enum): Checkboxes
    {
        $options = collect($enum::cases())->map(fn ($case) => [
            'value' => (string) $case->value,
            'label' => $case->getLabel(),
            'icon' => $case instanceof HasIcon ? $case->getIcon() : null,
        ])->all();

        return Checkboxes::make($name)
            ->label($label)
            ->options($options)
            ->buttons()
            ->rules(['nullable', 'array']);
    }

    protected function country(string $name = 'country', bool $required = false): Select
    {
        $options = $this->getCountryOptions();

        return Select::make($name)
            ->label(__('Country'))
            ->options($options)
            ->searchable()
            ->attributes(['clearable' => ! $required, 'clearLabel' => trans('form-builder::fields.clear')])
            ->default($this->vehicle()?->country_registration)
            ->rules([$required ? 'required' : 'nullable', Rule::in(array_keys($options))]);
    }
}
