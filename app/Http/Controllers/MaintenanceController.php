<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MaintenanceTypeMaintenance;
use App\Forms\MaintenanceForm;
use App\Models\Maintenance;
use App\Support\Html;
use App\Support\VehiclePresenter;
use App\Tables\MaintenancesTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends VehicleRecordController
{
    private const SMALL_CHECKS = [
        MaintenanceTypeMaintenance::TirePressureChecked,
        MaintenanceTypeMaintenance::LiquidsChecked,
    ];

    protected string $model = Maintenance::class;

    protected string $form = MaintenanceForm::class;

    protected string $table = MaintenancesTable::class;

    protected string $routeName = 'maintenances';

    protected ?string $priceColumn = 'total_price';

    protected bool $hasShowPage = true;

    protected function title(): string
    {
        return __('Maintenance & repairs');
    }

    protected function singularTitle(): string
    {
        return __('Maintenance');
    }

    protected function description(): string
    {
        return __('Here you can add the costs of maintenance, repairs and parts to get insight in their costs and get informed of important intervals. It is also possible to add small checks to get notified about their intervals. This category includes all costs incurred for preventive and periodic maintenance and repairs, as well as the replacement of worn and outdated parts. This does not include costs arising from damage or accidents.');
    }

    /**
     * Register a small check (tire pressure, liquids) without filling in the whole form.
     */
    public function smallCheck(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type_maintenance' => ['required', Rule::in(array_column(self::SMALL_CHECKS, 'value'))],
            'date' => ['nullable', 'date'],
        ]);

        $vehicle = $this->vehicle();

        $vehicle->maintenances()->create([
            'type_maintenance' => $data['type_maintenance'],
            'date' => $data['date'] ?? today()->format('Y-m-d'),
            'mileage' => $vehicle->mileage_latest ?? $vehicle->mileage_start ?? 0,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $this->singularTitle()])]);

        return back();
    }

    public function show(string $id): Response
    {
        /** @var Maintenance $maintenance */
        $maintenance = $this->record($id);
        $money = fn ($value) => '€ '.number_format((float) $value, 2, ',', '.');
        $type = MaintenanceTypeMaintenance::tryFrom((string) $maintenance->type_maintenance);

        return Inertia::render('Crud/Show', [
            'title' => __('Maintenance'),
            'indexUrl' => route('maintenances.index'),
            'indexTitle' => $this->title(),
            'editUrl' => route('maintenances.edit', $maintenance->id),
            'sections' => [
                ['title' => __('Maintenance'), 'items' => [
                    ['label' => __('Vehicle'), 'value' => $this->vehicle()->full_name, 'logo' => VehiclePresenter::summary($this->vehicle())['brandLogo']],
                    ['label' => __('Date'), 'value' => $maintenance->date?->isoFormat('ll'), 'icon' => 'gmdi-calendar-month-r'],
                    ['label' => __('Garage'), 'value' => $maintenance->garage ?: __('Unknown'), 'icon' => 'mdi-garage'],
                    ['label' => __('Mileage'), 'value' => filled($maintenance->mileage) ? $maintenance->mileage.' km' : null, 'icon' => 'gmdi-route'],
                ]],
                ['title' => __('Tasks'), 'items' => [
                    ['label' => __('Type maintenance'), 'html' => Html::badge($type?->getLabel() ?? __('No maintenance'), icon: match ($type) {
                        MaintenanceTypeMaintenance::Maintenance => 'mdi-car-wrench',
                        MaintenanceTypeMaintenance::SmallMaintenance => 'mdi-oil',
                        MaintenanceTypeMaintenance::BigMaintenance => 'mdi-engine',
                        default => 'gmdi-close-r',
                    })],
                    ['label' => __('MOT'), 'html' => Html::badge($maintenance->apk ? __('MOT') : __('No MOT'), icon: $maintenance->apk ? 'gmdi-security' : 'gmdi-close-r')],
                    ['label' => __('MOT date'), 'value' => $maintenance->apk_date?->isoFormat('ll') ?? __('Unknown'), 'icon' => 'gmdi-security'],
                    ['label' => __('Airco check'), 'html' => Html::badge($maintenance->airco_check ? __('Airco check') : __('No airco check'), icon: $maintenance->airco_check ? 'mdi-air-conditioner' : 'gmdi-close-r')],
                    ['label' => __('Description'), 'value' => $maintenance->description ?: __('None'), 'icon' => 'gmdi-assignment-turned-in-o'],
                    ['label' => __('Total price'), 'value' => filled($maintenance->total_price) ? $money($maintenance->total_price) : __('Unknown'), 'icon' => 'mdi-hand-coin-outline'],
                    ['label' => __('Payment method'), 'value' => $maintenance->payment_method?->getLabel() ?? __('None')],
                    ...collect($maintenance->tasks ?? [])
                        ->filter(fn ($task) => filled($task['task'] ?? null))
                        ->map(fn ($task) => ['label' => $task['task'], 'value' => $money($task['price'] ?? 0), 'icon' => ($task['icon'] ?? null) ?: 'mdi-car-wrench'])
                        ->values()
                        ->all(),
                ]],
            ],
        ]);
    }

    protected function indexProps(): array
    {
        return [
            'smallCheck' => [
                'url' => route('maintenances.small-check'),
                'options' => $this->getSmallCheckOptions(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getSmallCheckOptions(): array
    {
        return collect(self::SMALL_CHECKS)
            ->mapWithKeys(fn (MaintenanceTypeMaintenance $type) => [$type->value => $type->getLabel()])
            ->all();
    }
}
