<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ParkingResource\Pages;
use App\Models\Insurance;
use App\Models\Parking;
use App\Models\Vehicle;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Range;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Database\Eloquent\Builder;

class ParkingResource extends Resource
{
    protected static ?string $model = Parking::class;

    protected static ?string $navigationIcon = 'fas-parking';

    public static function getNavigationLabel(): string
    {
        return __('Parking');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Parking');
    }

    public static function getModelLabel(): string
    {
        return __('Parking');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->relationship('vehicle')
                    ->default(fn (Vehicle $vehicle) => $vehicle->selected()->latest()->first()->id)
                    ->options(function (Vehicle $vehicle) {
                        $vehicles = Vehicle::get();

                        $vehicles->car = $vehicles->map(function ($index) {
                            return $index->car = $index->full_name . ' (' . $index->license_plate . ')';
                        });

                        return $vehicles->pluck('car', 'id');
                    }),
                ToggleButtons::make('type')
                    ->label(__('Type'))
                    ->inline()
                    ->grouped()
                    ->options([
                        'street' => __('Street'),
                        'garage' => __('Parking garage'),
                    ])
                    ->icons([
                        'street' => 'maki-parking-paid',
                        'garage' => 'maki-parking-garage',
                    ]),
                TextInput::make('location')
                    ->label(__('Locatie'))
                    ->required()
                    ->maxLength(100),
                DateTimePicker::make('start_time')
                    ->label(__('Start time'))
                    ->required()
                    ->native(false)
                    ->displayFormat('d-m-Y H:i')
                    ->maxDate(now()),
                DateTimePicker::make('end_time')
                    ->label(__('End time'))
                    ->native(false)
                    ->displayFormat('d-m-Y H:i'),
                TextInput::make('price')
                    ->label(__('Price'))
                    ->numeric()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->required()
                    ->prefix('€')
                    ->step(0.01),
                ToggleButtons::make('payment_method')
                    ->label(__('Payment method'))
                    ->inline()
                    ->grouped()
                    ->options([
                        'cash' => __('Cash'),
                        'card' => __('Card'),
                        'app' => __('App'),
                    ])
                    ->icons([
                        'cash' => 'mdi-hand-coin-outline',
                        'card' => 'gmdi-credit-card',
                        'app' => 'mdi-cellphone-wireless',
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                return $query->whereHas('vehicle', function ($query) {
                    $query->selected();
                })->orderByDesc('end_time');
            })
            ->columns([
                TextColumn::make('start_time')
                    ->label(__('Start time'))
                    ->date()
                    ->formatStateUsing(function (Parking $parking) {
                        return $parking->start_time->isoFormat('MMM D, Y  H:mm') . ' t/m ' . $parking->end_time->isoFormat('MMM D, Y H:mm');
                    })
                    ->icon('gmdi-calendar-month-r'),
                TextColumn::make('location')
                    ->label(__('Location'))
                    ->icon('gmdi-location-on-r'),
                TextColumn::make('price')
                    ->label(__('Price'))
                    ->icon('mdi-hand-coin-outline')
                    ->money('EUR')
                    ->summarize([
                        Average::make()->label(__('Price average')),
                        Range::make()->label(__('Price range')),
                    ]),
                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->icon(fn (string $state): string => match ($state) {
                        'street' => 'maki-parking-paid',
                        'garage' => 'maki-parking-garage',
                        default => '',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'street' => __('Street'),
                        'garage' => __('Parking garage'),
                    }),
                TextColumn::make('payment_method')
                    ->badge()
                    ->color('gray')
                    ->icon(fn (string $state): string => match ($state) {
                        'cash' => 'mdi-hand-coin-outline',
                        'card' => 'gmdi-credit-card',
                        'app' => 'mdi-cellphone-wireless',
                        default => '',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'cash' => __('Cash'),
                        'card' => __('Card'),
                        'app' => __('App'),
                    }),
            ])
            ->filters([
                Filter::make('time')
                    ->label(__('Time'))
                    ->form([
                        DateTimePicker::make('time_from')
                            ->label(__('Time from'))
                            ->native(false),
                        DateTimePicker::make('time_until')
                            ->label(__('Time until'))
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['time_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('start_time', '>=', $date),
                            )
                            ->when(
                                $data['time_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('end_time', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['time_from'] && $data['time_until']) {
                            $indicators['time'] = __('Time from :from until :until', [
                                'from' => Carbon::parse($data['time_from'])->isoFormat('MMM D, Y H:mm'),
                                'until' => Carbon::parse($data['time_until'])->isoFormat('MMM D, Y H:mm'),
                            ]);
                        } elseif ($data['time_from']) {
                            $indicators['time'] = __('Time from :from', [
                                'from' => Carbon::parse($data['time_from'])->isoFormat('MMM D, Y H:mm'),
                            ]);
                        } elseif ($data['time_until']) {
                            $indicators['time'] = __('Date until :until', [
                                'until' => Carbon::parse($data['time_until'])->isoFormat('MMM D, Y H:mm'),
                            ]);
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParkings::route('/'),
            'create' => Pages\CreateParking::route('/create'),
            'edit' => Pages\EditParking::route('/{record}/edit'),
        ];
    }
}