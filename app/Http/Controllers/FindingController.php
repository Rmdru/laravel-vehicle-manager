<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FindingSource;
use App\Enums\FindingStatus;
use App\Forms\FindingForm;
use App\Models\Finding;
use App\Services\FindingService;
use App\Support\Html;
use App\Support\VehiclePresenter;
use App\Tables\FindingsTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FindingController extends VehicleRecordController
{
    protected string $model = Finding::class;

    protected string $form = FindingForm::class;

    protected string $table = FindingsTable::class;

    protected string $routeName = 'findings';

    // The estimated costs are no costs made.
    protected ?string $priceColumn = null;

    protected bool $hasShowPage = true;

    protected function title(): string
    {
        return __('Findings');
    }

    protected function singularTitle(): string
    {
        return __('Finding');
    }

    protected function description(): string
    {
        return __('Here you keep what needs attention until it is resolved: what the garage advised, what you notice yourself, and the defects the MOT found. Put what is to be done on the list for the garage, and tick it off when you record the maintenance.');
    }

    /**
     * Added from the list for the garage: on it at once.
     */
    protected function createData(Request $request): array
    {
        return $request->boolean('for_garage') ? ['for_garage' => true] : [];
    }

    /**
     * A finding that is resolved gets a date, one that is open again loses it.
     */
    protected function prepare(array $data, ?Model $record): array
    {
        $resolved = ($data['status'] ?? null) !== FindingStatus::Open->value;

        return [
            ...$data,
            'resolved_date' => $resolved ? ($record?->resolved_date ?? today()) : null,
            'maintenance_id' => $resolved ? $record?->maintenance_id : null,
            // Done with, it no longer goes to the garage.
            'for_garage' => ! $resolved && ($data['for_garage'] ?? false),
        ];
    }

    public function show(string $id): Response
    {
        /** @var Finding $finding */
        $finding = $this->record($id);
        $service = app(FindingService::class);
        $diagnosis = $finding->diagnosis;

        return Inertia::render('Crud/Show', [
            'title' => $finding->title,
            'indexUrl' => route('findings.index'),
            'indexTitle' => $this->title(),
            'editUrl' => route('findings.edit', $finding->id),
            'actions' => array_values(array_filter([
                $finding->status === FindingStatus::Open ? [
                    'label' => $finding->for_garage ? __('Take off the list for the garage') : __('Put on the list for the garage'),
                    'url' => route('findings.garage', $finding->id),
                    'icon' => 'mdi:garage-alert',
                ] : null,
                $finding->status === FindingStatus::Open ? [
                    'label' => __('Resolved'),
                    'url' => route('findings.resolve', $finding->id),
                    'icon' => 'ic:round-check',
                ] : null,
                $finding->source === FindingSource::Complaint ? [
                    'label' => $diagnosis ? __('Diagnose again') : __('What may it be?'),
                    'url' => route('findings.diagnose', $finding->id),
                    'icon' => 'ic:round-auto-awesome',
                    'disabled' => ! $service->canDiagnose($finding),
                    'busyLabel' => __('Thinking…'),
                ] : null,
            ])),
            'sections' => array_values(array_filter([
                ['title' => $this->singularTitle(), 'items' => [
                    ['label' => __('Vehicle'), 'value' => $this->vehicle()->full_name, 'logo' => VehiclePresenter::summary($this->vehicle())['brandLogo']],
                    ['label' => __('Source'), 'html' => Html::badge($finding->source->getLabel(), icon: $finding->source->getIcon())],
                    ['label' => __('Urgency'), 'html' => Html::badge($finding->severity->getLabel(), icon: $finding->severity->getIcon())],
                    ['label' => __('Status'), 'html' => Html::badge($finding->status->getLabel(), icon: $finding->status->getIcon())],
                    ['label' => __('Date'), 'value' => $finding->date->isoFormat('ll'), 'icon' => 'ic:round-calendar-month'],
                    ['label' => __('Mileage'), 'value' => filled($finding->mileage) ? number_format($finding->mileage, 0, ',', '.').' km' : null, 'icon' => 'ic:baseline-route'],
                    ['label' => __('Garage'), 'value' => $finding->garage, 'icon' => 'mdi:garage'],
                    ['label' => __('Estimated costs'), 'value' => $finding->estimated_cost ? '€ '.number_format($finding->estimated_cost, 2, ',', '.') : null, 'icon' => 'mdi:hand-coin-outline'],
                    ['label' => __('Components'), 'value' => implode(', ', $service->present($finding)['components']) ?: null, 'icon' => 'mdi:clipboard-list-outline'],
                    ['label' => __('On the list for the garage'), 'value' => $finding->status === FindingStatus::Open ? ($finding->for_garage ? __('Yes') : __('No')) : null, 'icon' => 'mdi:garage-alert'],
                    ['label' => __('Resolved on'), 'value' => $finding->resolved_date?->isoFormat('ll'), 'icon' => 'ic:round-check'],
                    ['label' => __('Resolved with'), 'value' => $finding->maintenance ? __('Maintenance of :date', ['date' => $finding->maintenance->date->isoFormat('ll')]) : null, 'icon' => 'mdi:car-wrench'],
                    ['label' => __('Description'), 'value' => $finding->description, 'icon' => 'ic:outline-assignment-turned-in'],
                ]],
                $diagnosis ? $this->diagnosis($diagnosis, $finding) : null,
            ])),
        ]);
    }

    /**
     * What the AI model made of the complaint, as a section of the page.
     *
     * @param  array<string, mixed>  $diagnosis
     * @return array<string, mixed>
     */
    private function diagnosis(array $diagnosis, Finding $finding): array
    {
        $likelihoods = ['high' => __('Likely'), 'medium' => __('Possible'), 'low' => __('Less likely')];
        $driving = [
            'stop' => [__('Do not drive on'), 'ic:round-warning'],
            'careful' => [__('Drive on carefully, have it looked at soon'), 'ic:round-error'],
            'fine' => [__('Safe to drive, have it looked at when convenient'), 'ic:round-check'],
        ][$diagnosis['driving']] ?? null;

        return [
            'title' => __('What it may be'),
            'icon' => 'ic:round-auto-awesome',
            'items' => [
                ['label' => __('Driving'), 'html' => $driving ? Html::badge($driving[0], icon: $driving[1]).'<p class="mt-1 text-sm">'.e($diagnosis['driving_explanation']).'</p>' : null],
                ...collect($diagnosis['causes'])->map(fn (array $cause) => [
                    'label' => $cause['cause'],
                    'html' => Html::badge($likelihoods[$cause['likelihood']] ?? $cause['likelihood'])
                        .'<p class="mt-1 text-sm">'.e($cause['explanation']).'</p>'
                        .($cause['cost_range'] !== '' ? '<p class="mt-1 text-sm text-muted-foreground">'.e(__('Usually :range', ['range' => $cause['cost_range']])).'</p>' : ''),
                ])->all(),
                ['label' => __('What to tell the garage'), 'value' => $diagnosis['what_to_tell_the_garage'] ?: null, 'icon' => 'mdi:garage'],
                ['label' => __('What you can check yourself'), 'value' => collect($diagnosis['self_checks'])->map(fn (string $check) => '• '.$check)->implode("\n") ?: null, 'icon' => 'mdi:clipboard-check-outline'],
                ['label' => __('Indicative'), 'value' => __('Generated on :date by :model. Have the garage confirm the cause before a repair.', ['date' => $finding->diagnosed_at?->isoFormat('D MMMM HH:mm'), 'model' => $diagnosis['model'] ?? '']), 'icon' => 'ic:round-info'],
            ],
        ];
    }

    /**
     * Puts a finding on the list for the garage, or takes it off.
     */
    public function garage(string $id): RedirectResponse
    {
        /** @var Finding $finding */
        $finding = $this->record($id);
        $finding->update(['for_garage' => ! $finding->for_garage]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $finding->for_garage ? __('Put on the list for the garage.') : __('Taken off the list for the garage.')]);

        return back();
    }

    public function resolve(string $id): RedirectResponse
    {
        /** @var Finding $finding */
        $finding = $this->record($id);
        $finding->resolve();
        $this->refreshNotifications();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name resolved.', ['name' => $finding->title])]);

        return back();
    }

    /**
     * Has the AI model say what a complaint may come from.
     */
    public function diagnose(string $id, FindingService $findings): RedirectResponse
    {
        /** @var Finding $finding */
        $finding = $this->record($id);

        abort_unless($finding->source === FindingSource::Complaint, 404);

        if (! $findings->canDiagnose($finding) || ! Cache::add("finding:{$finding->id}:diagnose", true, 120)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('This is being done already. Please wait a moment.')]);

            return back();
        }

        try {
            $findings->diagnose($finding, app()->getLocale());

            Inertia::flash('toast', ['type' => 'success', 'message' => __('The complaint has been looked into.')]);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('The AI model could not be reached. Please try again later.')]);
        } finally {
            Cache::forget("finding:{$finding->id}:diagnose");
        }

        return back();
    }
}
