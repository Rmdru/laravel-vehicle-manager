<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MaintenanceTypeMaintenance;
use App\Forms\MaintenanceForm;
use App\Models\Finding;
use App\Models\GarageListItem;
use App\Models\Maintenance;
use App\Services\FindingService;
use App\Services\MaintenancePlanService;
use App\Support\Html;
use App\Support\VehiclePresenter;
use App\Tables\MaintenancesTable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends VehicleRecordController
{
    protected string $model = Maintenance::class;

    protected string $form = MaintenanceForm::class;

    protected string $table = MaintenancesTable::class;

    protected string $routeName = 'maintenances';

    protected ?string $priceColumn = 'total_price';

    protected bool $hasShowPage = true;

    /** The findings the maintenance resolved, as ticked in the form; null when the form had none. */
    private ?array $findings = null;

    /** The lines of the list for the garage the maintenance was recorded with. */
    private array $garageLines = [];

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
        return __('Here you can add the costs of maintenance, repairs and parts to get insight in their costs and get informed of important intervals. Checks such as the tire pressure are ticked off in the maintenance plan. This category includes all costs incurred for preventive and periodic maintenance and repairs, as well as the replacement of worn and outdated parts. This does not include costs arising from damage or accidents.');
    }

    /**
     * A component of the maintenance plan, or a MOT the RDW registered with
     * the defects that were found at it.
     */
    protected function createData(Request $request): array
    {
        // Recorded from the list for the garage: what is on it, as tasks, resolved by it.
        if ($request->boolean('for_garage')) {
            $listed = $this->vehicle()->findings()->open()->where('for_garage', true)->orderBy('date')->get();
            $lines = $this->vehicle()->garageListItems()->oldest()->get();

            return [
                'findings' => $listed->pluck('id')->all(),
                'tasks' => [
                    ...$listed->map(fn (Finding $finding) => ['task' => $finding->title, 'price' => $finding->estimated_cost, 'components' => (array) $finding->components]),
                    ...$lines->map(fn (GarageListItem $line) => ['task' => $line->text, 'price' => null, 'components' => []]),
                ],
                // Taken off the list once the maintenance is stored.
                'garage_lines' => $lines->pluck('id')->implode(','),
            ];
        }

        // Recorded from the maintenance plan: a task for the component.
        $component = config('maintenance_plan.components.'.$request->query('component', ''));

        if (is_array($component) && is_string($request->query('component'))) {
            return [
                'tasks' => [['task' => __($component['label']), 'price' => null, 'components' => [$request->query('component')]]],
            ];
        }

        $renewal = $this->vehicle()->rdw_data['apk_renewed'] ?? null;

        if (! $request->boolean('rdw_apk') || empty($renewal['detected_at'])) {
            return [];
        }

        $inspection = $this->vehicle()->latestInspection(Carbon::parse($renewal['detected_at'])->subDays(60));
        $defects = collect($inspection['defects'] ?? [])
            ->map(fn (array $defect) => '- '.$defect['description'].($defect['count'] > 1 ? ' ('.$defect['count'].'x)' : ''));

        return array_filter([
            'apk' => true,
            'date' => $inspection['date'] ?? null,
            'apk_date' => $inspection['date'] ?? null,
            'description' => collect([__('MOT')])
                ->when($defects->isNotEmpty(), fn ($lines) => $lines->push(__('Defects found by the inspection:'), ...$defects))
                ->implode("\n"),
        ]);
    }

    protected function prepare(array $data, ?Model $record): array
    {
        $this->findings = array_key_exists('findings', $data) ? array_values(array_filter((array) $data['findings'], 'is_string')) : null;
        $this->garageLines = array_values(array_filter(array_map('intval', explode(',', (string) ($data['garage_lines'] ?? '')))));
        unset($data['findings'], $data['garage_lines']);

        return $data;
    }

    protected function saved(Model $record): void
    {
        if ($this->findings !== null) {
            /** @var Maintenance $record */
            app(FindingService::class)->resolveWith($record, $this->findings);
        }

        $this->vehicle()->garageListItems()->whereIn('id', $this->garageLines)->delete();
    }

    protected function formData(Model $record): array
    {
        return [
            ...parent::formData($record),
            'findings' => $this->vehicle()->findings()->where('maintenance_id', $record->getKey())->pluck('id')->all(),
        ];
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
                    ['label' => __('Date'), 'value' => $maintenance->date->isoFormat('ll'), 'icon' => 'ic:round-calendar-month'],
                    ['label' => __('Garage'), 'value' => $maintenance->garage ?: __('Unknown'), 'icon' => 'mdi:garage'],
                    ['label' => __('Mileage'), 'value' => filled($maintenance->mileage) ? $maintenance->mileage.' km' : null, 'icon' => 'ic:baseline-route'],
                ]],
                ['title' => __('Tasks'), 'items' => [
                    ['label' => __('Type maintenance'), 'html' => Html::badge($type?->getLabel() ?? __('No maintenance'), icon: match ($type) {
                        MaintenanceTypeMaintenance::Maintenance => 'mdi:car-wrench',
                        MaintenanceTypeMaintenance::SmallMaintenance => 'mdi:oil',
                        MaintenanceTypeMaintenance::BigMaintenance => 'mdi:engine',
                        MaintenanceTypeMaintenance::OilService => 'mdi:oil-level',
                        MaintenanceTypeMaintenance::InspectionService => 'mdi:clipboard-check-outline',
                        MaintenanceTypeMaintenance::Longlife => 'mdi:history',
                        default => 'ic:round-close',
                    })],
                    ['label' => __('MOT'), 'html' => Html::badge($maintenance->apk ? __('MOT') : __('No MOT'), icon: $maintenance->apk ? 'ic:baseline-security' : 'ic:round-close')],
                    ['label' => __('MOT date'), 'value' => $maintenance->apk_date?->isoFormat('ll') ?? __('Unknown'), 'icon' => 'ic:baseline-security'],
                    ['label' => __('Airco check'), 'html' => Html::badge($maintenance->airco_check ? __('Airco check') : __('No airco check'), icon: $maintenance->airco_check ? 'mdi:air-conditioner' : 'ic:round-close')],
                    ['label' => __('Description'), 'value' => $maintenance->description ?: __('None'), 'icon' => 'ic:outline-assignment-turned-in'],
                    ['label' => __('Total price'), 'value' => filled($maintenance->total_price) ? $money($maintenance->total_price) : __('Unknown'), 'icon' => 'mdi:hand-coin-outline'],
                    ['label' => __('Payment method'), 'value' => $maintenance->payment_method?->getLabel() ?? __('None')],
                    ...collect($maintenance->tasks ?? [])
                        ->filter(fn ($task) => filled($task['task'] ?? null))
                        ->map(fn ($task) => [
                            'label' => $task['task'],
                            'value' => $money($task['price'] ?? 0),
                            // The icon of the first component it serviced, for the maintenance plan.
                            'icon' => config('maintenance_plan.components.'.(MaintenancePlanService::componentsOf($task)[0] ?? '').'.icon')
                                ?: (($task['icon'] ?? null) ?: 'mdi:car-wrench'),
                        ])
                        ->values()
                        ->all(),
                ]],
            ],
        ]);
    }
}
