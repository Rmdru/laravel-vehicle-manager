<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VignetteResource\Pages;
use App\Models\Vehicle;
use App\Models\Vignette;
use App\Traits\CountryOptions;
use App\Traits\IsMobile;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ReplicateAction;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Range;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

class VignetteResource extends Resource
{
    use CountryOptions;
    use IsMobile;

    protected static ?string $model = Vignette::class;

    protected static ?string $navigationIcon = 'mdi-sticker-text';

    public static function getNavigationLabel(): string
    {
        return __('Vignettes');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Vignettes');
    }

    public static function getModelLabel(): string
    {
        return __('Vignette');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                Action::make('info')
                    ->modalHeading(__('Vignette'))
                    ->modalContent(new HtmlString(__('Here you can add the costs of vignettes to get insight in their costs. This category includes the one-off costs for a vignette that gives access to a specific area for a certain period.')))
                    ->modalIcon('mdi-sticker-text')
                    ->modalCancelActionLabel(__('Close'))
                    ->modalSubmitAction(false),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                return $query->whereHas('vehicle', function ($query) {
                    $query->selected();
                });
            })
            ->columns([
                Split::make([
                    TextColumn::make('country')
                        ->sortable()
                        ->formatStateUsing(function ($record) {
                            return Livewire::mount('CountryFlag', [
                                'country' => $record->country,
                                'showName' => true,
                            ]);
                        })
                        ->html()
                        ->label(__('Country')),
                    TextColumn::make('start_date')
                        ->label(__('Start date'))
                        ->sortable()
                        ->date()
                        ->icon('gmdi-calendar-month-r')
                        ->formatStateUsing(function (Vignette $vignette) {
                            return $vignette->start_date->isoFormat('MMM D, Y')
                                . ' - ' .
                                (! empty($vignette->end_date)
                                    ? $vignette->end_date->isoFormat('MMM D, Y')
                                    : __('forever')
                                );
                        }),
                    TextColumn::make('price')
                        ->label(__('Price'))
                        ->icon('mdi-hand-coin-outline')
                        ->sortable()
                        ->money('EUR')
                        ->summarize([
                            Average::make()->label(__('Price average')),
                            Range::make()->label(__('Price range')),
                        ]),
                ])
                    ->from('lg'),
                Panel::make([
                    TextColumn::make('areas')
                        ->label(__('Areas'))
                        ->sortable()
                        ->icon('mdi-map-marker-radius'),
                    TextColumn::make('comments')
                        ->icon('gmdi-text-fields-r')
                        ->label(__('Comments')),
                ])
                    ->collapsible(),
            ])
            ->filters([
                Filter::make('date')
                    ->label(__('Date'))
                    ->form([
                        DatePicker::make('start_date')
                            ->label(__('Start date'))
                            ->native((new self)->isMobile()),
                        DatePicker::make('end_date')
                            ->label(__('End date'))
                            ->native((new self)->isMobile()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['start_date'],
                                fn(Builder $query, $date): Builder => $query->whereDate('start_date', '>=', $date),
                            )
                            ->when(
                                $data['end_date'],
                                fn(Builder $query, $date): Builder => $query->whereDate('end_date', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['start_date'] && $data['end_date']) {
                            $indicators['date'] = __('Date from :start until :end', [
                                'start' => Carbon::parse($data['start_date'])->isoFormat('MMM D, Y'),
                                'end' => Carbon::parse($data['end_date'])->isoFormat('MMM D, Y'),
                            ]);
                        } else if ($data['start_date']) {
                            $indicators['date'] = __('Date from :start', [
                                'start' => Carbon::parse($data['date_from'])->isoFormat('MMM D, Y'),
                            ]);
                        } else if ($data['end_date']) {
                            $indicators['date'] = __('Date until :end', [
                                'end' => Carbon::parse($data['end_date'])->isoFormat('MMM D, Y'),
                            ]);
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make(),
                    ReplicateAction::make()
                        ->label(__('Duplicate'))
                        ->icon('gmdi-file-copy-r')
                        ->requiresConfirmation()
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('start_date', 'desc');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Fieldset::make('vignette')
                    ->label(__('Vignette'))
                    ->schema([
                        Select::make('vehicle_id')
                            ->disabled()
                            ->label(__('Vehicle'))
                            ->required()
                            ->searchable()
                            ->native((new self)->isMobile())
                            ->relationship('vehicle')
                            ->default(fn(Vehicle $vehicle) => $vehicle->selected()->first()->id ?? null)
                            ->options(function (Vehicle $vehicle) {
                                $vehicles = Vehicle::all();

                                $vehicles->car = $vehicles->map(function ($index) {
                                    return $index->full_name_with_license_plate;
                                });

                                return $vehicles->pluck('full_name_with_license_plate', 'id');
                            }),
                        TextInput::make('price')
                            ->label(__('Price'))
                            ->numeric()
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->required()
                            ->prefix('€')
                            ->step(0.01),
                    ]),
                Fieldset::make('validity')
                    ->label(__('Validity'))
                    ->schema([
                        DatePicker::make('start_date')
                            ->label(__('Start date'))
                            ->required()
                            ->native((new self)->isMobile())
                            ->displayFormat('d-m-Y'),
                        DatePicker::make('end_date')
                            ->label(__('End date'))
                            ->native((new self)->isMobile())
                            ->displayFormat('d-m-Y'),
                        Select::make('country')
                            ->label(__('Country'))
                            ->searchable()
                            ->native((new self)->isMobile())
                            ->options((new self())->getCountryOptions())
                            ->default(Vehicle::selected()->first()->country_registration),
                        Textarea::make('areas')
                            ->label(__('Areas')),
                    ]),
                Fieldset::make('other')
                    ->label(__('Other'))
                    ->schema([
                        Textarea::make('comments')
                            ->label(__('Comments')),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVignettes::route('/'),
            'create' => Pages\CreateVignette::route('/create'),
            'edit' => Pages\EditVignette::route('/{record}/edit'),
        ];
    }
}
