<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceResource\Pages;
use App\Models\Maintenance;
use App\Models\Vehicle;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Range;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Session;

class MaintenanceResource extends Resource
{
    protected static ?string $model = Maintenance::class;

    protected static ?string $navigationIcon = 'mdi-car-wrench';

    public static function getNavigationLabel(): string
    {
        return __('Maintenance');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Maintenance');
    }

    public static function getModelLabel(): string
    {
        return __('Maintenance');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                return $query->whereHas('vehicle', function ($query) {
                    $query->selected();
                })->orderByDesc('date');
            })
            ->headerActions([
                Action::make('small_checks')
                    ->label(__('Add small check'))
                    ->form([
                        Select::make('type_maintenance')
                            ->label(__('Type'))
                            ->options([
                                'tire_pressure' => __('Tire pressure checked'),
                                'liquids_checked' => __('Liquids checked'),
                            ])
                            ->required(),
                        DatePicker::make('date')
                            ->default(now())
                            ->label(__('Date')),
                    ])
                    ->action(function (array $data): void {
                        Maintenance::create([
                            'vehicle_id' => Session::get('vehicle_id'),
                            'type_maintenance' => $data['type_maintenance'],
                            'date' => $data['date'] ?? Carbon::today()->format('Y-m-d'),
                            'mileage' => Vehicle::selected()->first()->mileage_latest,
                        ]);
                    }),
            ])
            ->columns([
                Split::make([
                    TextColumn::make('date')
                        ->label(__('Date'))
                        ->sortable()
                        ->date()
                        ->icon('gmdi-calendar-month-r'),
                    TextColumn::make('garage')
                        ->sortable()
                        ->label(__('Garage'))
                        ->icon('mdi-garage')
                        ->default(__('Unknown'))
                        ->searchable(),
                    TextColumn::make('type_maintenance')
                        ->sortable()
                        ->label(__('Type maintenance'))
                        ->badge()
                        ->default('')
                        ->formatStateUsing(fn(string $state) => match ($state) {
                            'tire_pressure' => __('Tire pressure checked'),
                            'liquids_checked' => __('Liquids checked'),
                            'maintenance' => __('Maintenance'),
                            'small_maintenance' => __('Small maintenance'),
                            'big_maintenance' => __('Big maintenance'),
                            default => __('No maintenance'),
                        })
                        ->icon(fn(string $state): string => match ($state) {
                            'tire_pressure' => 'mdi-car-tire-alert',
                            'liquids_checked' => 'mdi-oil',
                            'maintenance' => 'mdi-car-wrench',
                            'small_maintenance' => 'mdi-oil',
                            'big_maintenance' => 'mdi-engine',
                            default => 'gmdi-close-r',
                        })
                        ->color('gray'),
                    TextColumn::make('apk')
                        ->icon(fn(Maintenance $maintenance) => $maintenance->apk ? 'gmdi-security' : 'gmdi-close-r')
                        ->sortable()
                        ->badge()
                        ->color('gray')
                        ->formatStateUsing(fn(Maintenance $maintenance) => $maintenance->apk ? __('MOT') : __('No MOT'))
                        ->label(__('MOT')),
                    TextColumn::make('mileage')
                        ->sortable()
                        ->label(__('Mileage'))
                        ->icon('gmdi-route')
                        ->suffix(' km'),
                    TextColumn::make('total_price')
                        ->sortable()
                        ->label(__('Total price'))
                        ->icon('mdi-hand-coin-outline')
                        ->money('EUR')
                        ->default(__('Unknown'))
                        ->summarize([
                            Average::make()->label(__('Total price average')),
                            Range::make()->label(__('Total price range')),
                        ]),
                ]),
            ])
            ->filters([
                Filter::make('date')
                    ->label(__('Date'))
                    ->form([
                        DatePicker::make('date_from')
                            ->label(__('Date from'))
                            ->native(false),
                        DatePicker::make('date_until')
                            ->label(__('Date until'))
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('date', '>=', $date),
                            )
                            ->when(
                                $data['date_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('date', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['date_from'] && $data['date_until']) {
                            $indicators['date'] = __('Date from :from until :until', [
                                'from' => Carbon::parse($data['date_from'])->isoFormat('MMM D, Y'),
                                'until' => Carbon::parse($data['date_until'])->isoFormat('MMM D, Y'),
                            ]);
                        } else if ($data['date_from']) {
                            $indicators['date'] = __('Date from :from', [
                                'from' => Carbon::parse($data['date_from'])->isoFormat('MMM D, Y'),
                            ]);
                        } else if ($data['date_until']) {
                            $indicators['date'] = __('Date until :until', [
                                'until' => Carbon::parse($data['date_until'])->isoFormat('MMM D, Y'),
                            ]);
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function form(Form $form): Form
    {
        $brands = config('vehicles.brands');

        return $form
            ->schema([
                Fieldset::make('maintenance')
                    ->label(__('Maintenance'))
                    ->schema([
                        Select::make('vehicle_id')
                            ->disabled()
                            ->label(__('Vehicle'))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->relationship('vehicle')
                            ->default(fn(Vehicle $vehicle) => $vehicle->selected()->onlyDrivable()->first()->id ?? null)
                            ->options(function (Vehicle $vehicle) use ($brands) {
                                $vehicles = Vehicle::onlyDrivable()->get();

                                $vehicles->car = $vehicles->map(function ($index) use ($brands) {
                                    return $index->car = $brands[$index->brand] . ' ' . $index->model . ' (' . $index->license_plate . ')';
                                });

                                return $vehicles->pluck('car', 'id');
                            }),
                        DatePicker::make('date')
                            ->label(__('Date'))
                            ->required()
                            ->native(false)
                            ->displayFormat('d-m-Y')
                            ->maxDate(now()),
                        TextInput::make('garage')
                            ->label(__('Garage'))
                            ->maxLength(100),
                        TextInput::make('mileage')
                            ->label(__('Mileage'))
                            ->required()
                            ->suffix(' km')
                            ->numeric(),
                    ]),
                Fieldset::make('tasks')
                    ->label(__('Tasks'))
                    ->schema([
                        Forms\Components\ToggleButtons::make('type_maintenance')
                            ->label(__('Type maintenance'))
                            ->inline()
                            ->grouped()
                            ->options([
                                'maintenance' => __('Maintenance'),
                                'small_maintenance' => __('Small maintenance'),
                                'big_maintenance' => __('Big maintenance'),
                            ]),
                        Toggle::make('apk')
                            ->label(__('MOT')),
                        DatePicker::make('apk_date')
                            ->label(__('MOT date'))
                            ->native(false)
                            ->displayFormat('d-m-Y'),
                        Toggle::make('airco_check')
                            ->label(__('Airco check')),
                        Textarea::make('description')
                            ->label(__('Description')),
                        TextInput::make('total_price')
                            ->label(__('Total price'))
                            ->numeric()
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->required()
                            ->prefix('€')
                            ->step(0.01),
                        Repeater::make('tasks')
                            ->label(__('Tasks'))
                            ->schema([
                                TextInput::make('task')
                                    ->label(__('Task')),
                                TextInput::make('price')
                                    ->label(__('Price'))
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->prefix('€')
                                    ->step(0.01),
                                TextInput::make('icon')
                                    ->label(__('Icon')),
                            ])
                            ->columnSpan(2)
                            ->columns(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenances::route('/'),
            'create' => Pages\CreateMaintenance::route('/create'),
            'view' => Pages\ViewMaintenance::route('/{record}'),
            'edit' => Pages\EditMaintenance::route('/{record}/edit'),
        ];
    }
}
