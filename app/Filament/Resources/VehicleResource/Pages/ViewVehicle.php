<?php

namespace App\Filament\Resources\VehicleResource\Pages;

use App\Filament\Resources\VehicleResource;
use App\Models\Vehicle;
use Filament\Infolists\Components\Fieldset;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\IconEntry\IconEntrySize;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Livewire;

class ViewVehicle extends ViewRecord
{
    protected static string $resource = VehicleResource::class;

    protected static string $view = 'filament.resources.vehicles.pages.view-vehicle';

    public function infolist(Infolist $infolist): Infolist
    {
        $brands = config('vehicles.brands');
        $powertrains = trans('powertrains');
        $powertrainsOptions = [];

        foreach ($powertrains as $key => $value) {
            $powertrainsOptions[$key] = $value['name'];
        }

        return $infolist
            ->schema([
                Fieldset::make('basic')
                    ->label(__('Basic'))
                    ->schema([
                        TextEntry::make('brand')
                            ->label(__('Brand'))
                            ->icon(fn(Vehicle $vehicle) => 'si-' . str($brands[$vehicle->brand])->replace(' ', '')->lower())
                            ->formatStateUsing(fn(Vehicle $vehicle) => $brands[$vehicle->brand]),
                        TextEntry::make('model')
                            ->icon('gmdi-directions-car-filled-r')
                            ->label(__('Model')),
                        TextEntry::make('version')
                            ->icon('gmdi-star')
                            ->label(__('Version')),
                        TextEntry::make('engine')
                            ->label(__('Engine'))
                            ->placeholder(__('Unknown'))
                            ->icon('mdi-engine'),
                        TextEntry::make('powertrain')
                            ->icon('gmdi-local-gas-station')
                            ->placeholder(__('Unknown'))
                            ->label(__('Powertrain'))
                            ->formatStateUsing(fn(string $state) => $powertrainsOptions[$state] ?? $state),
                        TextEntry::make('fuel_types')
                            ->label(__('Compatible fuel types'))
                            ->badge()
                            ->separator(','),
                    ]),
                Fieldset::make('ownership')
                    ->label(__('Ownership'))
                    ->schema([
                        TextEntry::make('country_registration')
                            ->formatStateUsing(function ($record) {
                                return Livewire::mount('country-flag', ['country' => $record->country_registration]);
                            })
                            ->html()
                            ->label(__('Country of registration')),
                        TextEntry::make('license_plate')
                            ->formatStateUsing(function ($record) {
                                return Livewire::mount('license-plate', ['vehicleId' => $record->id]);
                            })
                            ->html()
                            ->label(__('License plate')),
                        TextEntry::make('mileage_start')
                            ->icon('gmdi-route')
                            ->suffix(' km')
                            ->placeholder(__('Unknown'))
                            ->label(__('Mileage on purchase')),
                        TextEntry::make('mileage_start')
                            ->icon('gmdi-route')
                            ->suffix(' km')
                            ->placeholder(__('Unknown'))
                            ->label(__('Mileage'))
                            ->formatStateUsing(fn(Vehicle $vehicle) => $vehicle->mileage_latest ?? $vehicle->mileage_start),
                        TextEntry::make('purchase_date')
                            ->icon('gmdi-calendar-month-r')
                            ->date()
                            ->placeholder(__('Unknown'))
                            ->label(__('Purchase date')),
                        TextEntry::make('purchase_price')
                            ->icon('gmdi-local-offer-r')
                            ->placeholder(__('Unknown'))
                            ->money('EUR')
                            ->label(__('Purchase price')),
                        TextEntry::make('status')
                            ->icon('mdi-list-status')
                            ->placeholder(__('Unknown'))
                            ->formatStateUsing(fn(string $state) => match ($state) {
                                'drivable' => __('Drivable'),
                                'suspended' => __('Suspended'),
                                'seized' => __('Seized'),
                                'stolen' => __('Stolen'),
                                'sold' => __('Sold'),
                                'destroyed' => __('Destroyed'),
                            })
                            ->label(__('Status')),
                    ]),
                Fieldset::make('Privacy')
                    ->label(__('Privacy'))
                    ->schema([
                        TextEntry::make('is_private')
                            ->icon(fn(Vehicle $vehicle) => $vehicle->is_private ? 'gmdi-lock' : 'gmdi-public')
                            ->badge()
                            ->default(__('Public'))
                            ->color('gray')
                            ->formatStateUsing(fn(Vehicle $vehicle) => $vehicle->is_private ? __('Private') : __('Public'))
                            ->label(__('Privacy')),
                    ]),
                Fieldset::make('specifications')
                    ->label(__('Specifications'))
                    ->schema([
                        RepeatableEntry::make('specifications')
                            ->schema([
                                IconEntry::make('icon')
                                    ->hiddenLabel()
                                    ->hidden(fn(?string $state): bool => empty($state))
                                    ->size(IconEntrySize::ExtraLarge)
                                    ->icon(fn(string $state): string => $state),
                                TextEntry::make('name')
                                    ->hidden(fn(?string $state): bool => empty($state))
                                    ->hiddenLabel(),
                                TextEntry::make('value')
                                    ->hidden(fn(?string $state): bool => empty($state))
                                    ->hiddenLabel(),
                            ])
                            ->columns(3)
                            ->columnSpan(2)
                            ->grid(),
                    ]),
            ]);
    }
}
