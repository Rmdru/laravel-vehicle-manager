<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RefuelingClimateControl;
use App\Enums\RefuelingDrivingStyle;
use App\Enums\RefuelingPaymentMethod;
use App\Enums\RefuelingRoutes;
use App\Enums\RefuelingTires;
use App\Forms\RefuelingForm;
use App\Models\Refueling;
use App\Services\FuelAdviceService;
use App\Support\GasStationLogo;
use App\Support\Html;
use App\Tables\RefuelingsTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RefuelingController extends VehicleRecordController
{
    protected string $model = Refueling::class;

    protected string $form = RefuelingForm::class;

    protected string $table = RefuelingsTable::class;

    protected string $routeName = 'refuelings';

    protected ?string $priceColumn = 'total_price';

    protected bool $hasShowPage = true;

    protected function title(): string
    {
        return __('Refuelings');
    }

    protected function singularTitle(): string
    {
        return __('Refueling');
    }

    protected function description(): string
    {
        return __('Here you can add your refuelings and charge sessions to keep track of the costs and fuel consumption. This category only includes costs of fuel and energy to move and use the vehicle. The prices and quantities entered are the value stated on the receipt or invoice.');
    }

    public function show(string $id): Response
    {
        /** @var Refueling $refueling */
        $refueling = $this->record($id);
        $powertrain = trans('powertrains')[$this->vehicle()->powertrain] ?? [];
        $unit = $powertrain['unit_short'] ?? 'l';
        $consumptionUnit = $powertrain['consumption_unit'] ?? 'l/100km';
        $averages = Refueling::where('vehicle_id', $refueling->vehicle_id)
            ->selectRaw('AVG(fuel_consumption) as consumption, AVG(avg_speed) as speed')
            ->toBase()
            ->first();

        $number = fn ($value, int $decimals = 2) => number_format((float) $value, $decimals, ',', '.');
        $badges = fn (string $enum, $value) => collect((array) $value)
            ->filter()
            ->map(fn ($item) => Html::badge($enum::tryFrom((string) $item)?->getLabel() ?? (string) $item, icon: $enum::tryFrom((string) $item)?->getIcon()))
            ->implode(' ');

        return Inertia::render('Crud/Show', [
            'title' => __('Refueling'),
            'indexUrl' => route('refuelings.index'),
            'indexTitle' => $this->title(),
            'editUrl' => route('refuelings.edit', $refueling->id),
            'logo' => GasStationLogo::url($refueling->gas_station),
            'sections' => [
                ['title' => __('Refueling'), 'icon' => 'ic:round-local-gas-station', 'items' => [
                    ['label' => __('Gas station'), 'value' => $refueling->gas_station, 'icon' => 'ic:round-location-on'],
                    ['label' => __('Service by attendant'), 'html' => $refueling->service_by_attendant ? Html::badge(__('Service by attendant'), 'success', 'ic:baseline-star') : null],
                    ['label' => __('Country'), 'html' => Html::flag($refueling->country, showName: true) ?: null],
                    ['label' => __('Date'), 'value' => $refueling->date->isoFormat('ll'), 'icon' => 'ic:round-calendar-month'],
                ]],
                ['title' => __('Fuel'), 'icon' => 'mdi:fuel', 'items' => [
                    ['label' => __('Fuel type'), 'value' => trans('fuel_types')[$refueling->fuel_type] ?? $refueling->fuel_type, 'icon' => 'ic:round-local-gas-station'],
                    ['label' => __('Amount'), 'value' => $number($refueling->amount).' '.$unit, 'icon' => str($refueling->fuel_type)->contains('electric', true) ? 'mdi:battery-charging' : 'mdi:fuel'],
                    ['label' => __('Tank percentage after refueling'), 'value' => filled($refueling->percentage) ? $number($refueling->percentage, 0).'%' : null, 'icon' => 'mdi:water-percent'],
                    ['label' => __('Unit price'), 'value' => '€ '.$number($refueling->unit_price, 3).'/'.$unit, 'icon' => 'ic:baseline-local-offer'],
                    ['label' => __('Total price'), 'value' => '€ '.$number($refueling->total_price), 'icon' => 'mdi:hand-coin-outline'],
                    ['label' => __('Costs per kilometer'), 'value' => '€ '.$number($refueling->costs_per_kilometer, 3).'/km', 'icon' => 'uil:euro-circle'],
                ]],
                ['title' => __('Car'), 'icon' => 'ic:round-directions-car', 'items' => [
                    ['label' => __('Mileage'), 'value' => $refueling->mileage_begin.' km - '.$refueling->mileage_end.' km ('.($refueling->mileage_end - $refueling->mileage_begin).' km)', 'icon' => 'ic:round-route'],
                    ['label' => __('Fuel consumption'), 'html' => Html::badge(
                        $number($refueling->fuel_consumption).' '.$consumptionUnit,
                        $this->comparisonColor((float) $refueling->fuel_consumption, (float) $averages?->consumption, lowerIsBetter: true),
                        $this->comparisonIcon((float) $refueling->fuel_consumption, (float) $averages?->consumption),
                    )],
                    ['label' => __('Fuel consumption onboard computer'), 'value' => filled($refueling->fuel_consumption_onboard_computer) ? $number($refueling->fuel_consumption_onboard_computer).' '.$consumptionUnit : null, 'icon' => 'mdi:content-save'],
                    ['label' => __('Average speed'), 'html' => filled($refueling->avg_speed) ? Html::badge(
                        $number($refueling->avg_speed, 1).' km/h',
                        $this->comparisonColor((float) $refueling->avg_speed, (float) $averages?->speed, lowerIsBetter: false),
                        $this->comparisonIcon((float) $refueling->avg_speed, (float) $averages?->speed),
                    ) : null],
                    ['label' => __('Charge time'), 'value' => $refueling->charge_time?->format('H:i'), 'icon' => 'mdi:timer-sand'],
                ]],
                ['title' => __('Circumstances'), 'icon' => 'fa7-solid:cloud-sun-rain', 'items' => [
                    ['label' => __('Tires'), 'html' => $badges(RefuelingTires::class, $refueling->tires) ?: null],
                    ['label' => __('Climate control'), 'html' => $badges(RefuelingClimateControl::class, $refueling->climate_control) ?: null],
                    ['label' => __('Routes'), 'html' => $badges(RefuelingRoutes::class, $refueling->routes) ?: null],
                    ['label' => __('Driving style'), 'html' => $badges(RefuelingDrivingStyle::class, $refueling->driving_style) ?: null],
                    ['label' => __('Comments'), 'value' => $refueling->comments, 'icon' => 'ic:round-text-fields'],
                ]],
                ['title' => __('Payment'), 'icon' => 'mdi:bank-transfer', 'items' => [
                    ['label' => __('Payment method'), 'html' => $badges(RefuelingPaymentMethod::class, $refueling->payment_method) ?: null],
                    ['label' => __('Discount'), 'value' => $refueling->discount],
                ]],
            ],
        ]);
    }

    protected function prepare(array $data, ?Model $record): array
    {
        $vehicle = $this->vehicle();

        $refueling = $record instanceof Refueling ? clone $record : new Refueling;
        $refueling->fill([...$data, 'vehicle_id' => $vehicle->id])->setRelation('vehicle', $vehicle);
        $refueling->calculateConsumption();

        $data['fuel_consumption'] = $refueling->fuel_consumption;
        $data['costs_per_kilometer'] = $refueling->costs_per_kilometer;

        return $data;
    }

    protected function saved(Model $record): void
    {
        /** @var Refueling $record */
        $vehicle = $this->vehicle();

        if ($vehicle->mileage_latest < $record->mileage_end) {
            $vehicle->update(['mileage_latest' => $record->mileage_end]);
        }

        $this->recalculate($record->next());

        app(FuelAdviceService::class)->regenerateAfter($record);
    }

    public function destroy(string $id): RedirectResponse
    {
        /** @var Refueling $refueling */
        $refueling = $this->record($id);
        $next = $refueling->next();

        $response = parent::destroy($id);

        $this->recalculate($next);

        return $response;
    }

    /**
     * The consumption of a refueling depends on how full the tank was left the time before.
     */
    private function recalculate(?Refueling $refueling): void
    {
        if (! $refueling) {
            return;
        }

        $refueling->calculateConsumption();
        $refueling->save();
    }

    protected function formData(Model $record): array
    {
        return [...parent::formData($record), 'charge_time' => $record->getAttribute('charge_time')?->format('H:i')];
    }

    protected function formProps(): array
    {
        return ['variant' => 'refueling'];
    }

    protected function summary(Builder $query): array
    {
        $unit = trans('powertrains')[$this->vehicle()->powertrain]['consumption_unit'] ?? 'l/100km';
        $number = fn ($value) => number_format((float) $value, 2, ',', '.');

        $mostVisited = (clone $query)
            ->select('gas_station')
            ->selectRaw('COUNT(*) as visits')
            ->groupBy('gas_station')
            ->orderByDesc('visits')
            ->value('gas_station');

        $consumption = (clone $query)
            ->selectRaw('AVG(fuel_consumption) as average, MIN(fuel_consumption) as minimum, MAX(fuel_consumption) as maximum')
            ->toBase()
            ->first();

        if (! $mostVisited) {
            return [];
        }

        return [
            ['label' => __('Most visited gas station'), 'value' => $mostVisited],
            ...parent::summary($query),
            ['label' => __('Fuel consumption average'), 'value' => $number($consumption->average).' '.$unit],
            ['label' => __('Fuel consumption range'), 'value' => $number($consumption->minimum).' - '.$number($consumption->maximum).' '.$unit],
        ];
    }

    private function comparisonIcon(float $value, float $average): string
    {
        return match (round($value, 2) <=> round($average, 2)) {
            1 => 'ic:round-trending-up',
            -1 => 'ic:round-trending-down',
            default => 'mdi:approximately-equal',
        };
    }

    private function comparisonColor(float $value, float $average, bool $lowerIsBetter): string
    {
        if (round($value, 2) === round($average, 2)) {
            return 'warning';
        }

        return ($value < $average) === $lowerIsBetter ? 'success' : 'danger';
    }
}
