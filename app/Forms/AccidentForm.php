<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enums\AccidentAttributeType;
use App\Enums\AccidentSituation;
use App\Enums\AccidentType;
use App\Forms\Fields\Heading;
use TranquilTools\FormBuilder\Fields\Checkbox;
use TranquilTools\FormBuilder\Fields\Repeater;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;

class AccidentForm extends VehicleRecordForm
{
    public function fields(): array
    {
        return [
            Heading::make(__('You, your vehicle, your passengers and your objects')),
            $this->choice('type', __('Type'), $this->enumOptions(AccidentType::class), required: true),
            $this->dateTime('datetime', __('Date and time'), required: true)
                ->default(now()->format('Y-m-d H:i')),
            Text::make('location')
                ->label(__('Location'))
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->label(__('Description'))
                ->rules(['nullable', 'string']),
            Checkbox::make('guilty')
                ->label(__('Guilty'))
                ->rules(['boolean']),
            $this->choices('situation', __('Situation'), AccidentSituation::class)
                ->help(__('Select all that apply')),
            $this->money('damage_own', __('Own damage'))
                ->help(__('This includes all damage: damage to the vehicle, damage to objects in the vehicle and costs of the injury of occupants.')),
            $this->money('damage_own_insured', __('Own damage insured'))
                ->help(__('This includes the amount that the insurance has reimbursed to you for your damage.')),
            $this->money('damage_others', __('Damage to others'))
                ->help(__('This includes all damage: damage to other vehicles or objects and costs of the injury of involved people.')),
            $this->money('damage_others_insured', __('Insured damage to others'))
                ->help(__('This includes the amount that the insurance has reimbursed to you for the damage of the others.')),

            Heading::make(__('Other involved attributes, vehicles, people, witness, animals and objects')),
            Repeater::make('attributes')
                ->addActionLabel(__('Add'))
                ->fields([
                    Text::make('attribute_name')
                        ->label(__('Name'))
                        ->rules(['nullable', 'string', 'max:255']),
                    $this->choice('attribute_type', __('Type'), $this->enumOptions(AccidentAttributeType::class)),
                    Checkbox::make('attribute_guilty')->label(__('Guilty')),
                    Checkbox::make('attribute_witness')->label(__('Witness')),
                    $this->choices('attribute_situation', __('Situation'), AccidentSituation::class)
                        ->help(__('Select all that apply')),
                    Textarea::make('attribute_description')
                        ->label(__('Description'))
                        ->rules(['nullable', 'string']),
                ])
                ->rules(['nullable']),

            Submit::make()->label(__('Save')),
        ];
    }
}
