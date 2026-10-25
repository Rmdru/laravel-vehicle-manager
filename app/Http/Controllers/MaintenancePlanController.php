<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VehicleComponent;
use App\Services\MaintenancePlanAi;
use App\Services\MaintenancePlanService;
use App\Services\NotificationCenter;
use App\Services\PriceHistory;
use App\Support\CurrentVehicle;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MaintenancePlanController extends Controller
{
    /** Seconds the model is not asked again for the same vehicle: against a second click. */
    private const BUSY_FOR = 120;

    /** The label of each state. */
    private const STATES = [
        MaintenancePlanService::OVERDUE => 'Overdue',
        MaintenancePlanService::DUE => 'Due',
        MaintenancePlanService::CHECK => 'To be checked',
        MaintenancePlanService::UNKNOWN => 'Not known',
        MaintenancePlanService::SOON => 'Soon',
        MaintenancePlanService::OK => 'Done in time',
        MaintenancePlanService::NO_INTERVAL => 'When needed',
    ];

    public function index(MaintenancePlanService $plan, MaintenancePlanAi $ai, PriceHistory $prices): Response
    {
        $vehicle = CurrentVehicle::get();
        $options = MaintenancePlanService::options($vehicle);
        $stored = $plan->components($vehicle);
        $history = $prices->byComponent($vehicle);

        return Inertia::render('MaintenancePlan', [
            'components' => array_map(fn (array $item) => $this->present($item, $history->get($item['key'], collect())), $plan->plan($vehicle)),
            // The components the vehicle has not: to switch them on again.
            'inactive' => $stored->reject(fn (VehicleComponent $component) => $component->active)
                ->map(fn (VehicleComponent $component, string $key) => ['key' => $key, 'label' => $options[$key], 'icon' => config("maintenance_plan.components.{$key}.icon"), 'note' => $component->note])
                ->values()
                ->all(),
            'proposed' => $stored->contains(fn (VehicleComponent $component) => $component->source !== VehicleComponent::DEFAULT),
            'options' => $options,
            'proposals' => collect($ai->proposals($vehicle))->map(fn (array $keys, string $task) => ['task' => $task, 'components' => $keys])->values()->all(),
            'unsortedTasks' => count($ai->unsortedTasks($vehicle)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, array<string, mixed>>  $prices  What was paid for it, the most recent first.
     * @return array<string, mixed>
     */
    private function present(array $item, Collection $prices): array
    {
        $component = config("maintenance_plan.components.{$item['key']}");
        $number = fn (?int $value) => $value === null ? null : number_format($value, 0, ',', '.');
        $due = $item['due_date'] ? Carbon::parse($item['due_date']) : null;
        $done = $item['done'];

        return [
            'key' => $item['key'],
            'label' => __($component['label']),
            'icon' => $component['icon'],
            'state' => $item['state'],
            'stateLabel' => __(self::STATES[$item['state']]),
            'interval' => collect([
                $item['interval_km'] ? $number($item['interval_km']).' km' : null,
                $item['interval_months'] ? trans_choice(':count month|:count months', $item['interval_months']) : null,
            ])->filter()->implode(' · ') ?: null,
            'intervalKm' => $item['interval_km'],
            'intervalMonths' => $item['interval_months'],
            'source' => $item['source'],
            'note' => $item['note'],
            // Done today at once, or recorded with the form, this component filled in.
            'doneUrl' => route('maintenance-plan.done', $item['key']),
            'proposeUrl' => route('maintenance-plan.propose-one', $item['key']),
            'recordUrl' => route('maintenances.create', ['component' => $item['key']]),
            'isCheck' => isset($component['check']),
            'ownDoneDate' => $item['own_done_date'],
            'ownDoneKm' => $item['own_done_km'],
            'done' => $done === null ? null : [
                'date' => Carbon::parse($done['date'])->isoFormat('D MMMM YYYY'),
                'km' => $done['km'] === null ? null : $number($done['km']).' km',
                'garage' => $done['garage'],
                'price' => $done['price'] === null ? null : '€ '.number_format($done['price'], 2, ',', '.'),
                'url' => match (true) {
                    $done['maintenance_id'] !== null => route('maintenances.show', $done['maintenance_id']),
                    $done['product_id'] !== null => route('products.edit', $done['product_id']),
                    default => null,
                },
            ],
            // What was paid for it before, and where.
            'prices' => $prices->take(3)->map(fn (array $price) => [
                'date' => Carbon::parse($price['date'])->isoFormat('MMM YYYY'),
                'garage' => $price['garage'],
                'price' => '€ '.number_format($price['price'], 2, ',', '.'),
            ])->values()->all(),
            // Never recorded: counted from new, the day it was built.
            'assumedSince' => $item['assumed_since'] ? Carbon::parse($item['assumed_since'])->isoFormat('D MMMM YYYY') : null,
            // To be checked, it is overdue going by its age only: no date to give.
            'due' => $due === null || $item['state'] === MaintenancePlanService::CHECK ? null : [
                'date' => $due->isoFormat('D MMMM YYYY'),
                'relative' => $due->isToday() ? __('Today') : $due->diffForHumans(),
                'km' => $item['due_km'] === null ? null : $number($item['due_km']).' km',
                'remainingKm' => $item['remaining_km'],
                'byDistance' => $item['due_by'] === 'distance',
                'estimated' => $item['estimated'],
            ],
        ];
    }

    /**
     * Changes a component: its intervals, whether the vehicle has it, and
     * when it was done last as the owner knows it.
     */
    public function update(Request $request, string $key, MaintenancePlanService $plan): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();
        $component = $plan->components($vehicle)->get($key);

        abort_if($component === null, 404);

        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'interval_km' => ['nullable', 'integer', 'min:1', 'max:500000'],
            'interval_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'done_date' => ['nullable', 'date', 'before_or_equal:today'],
            'done_km' => ['nullable', 'integer', 'min:0'],
        ]);

        $component->fill($data);

        // Changed intervals are the owner's: the model leaves them alone from now on.
        if ($component->isDirty(['active', 'interval_km', 'interval_months'])) {
            $component->source = VehicleComponent::MANUAL;
        }

        $component->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name updated.', ['name' => __(config("maintenance_plan.components.{$key}.label"))])]);

        return back();
    }

    /**
     * Ticks a component off as done today, such as a check of the tire pressure.
     */
    public function done(string $key, MaintenancePlanService $plan, NotificationCenter $notifications): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();

        abort_unless($plan->components($vehicle)->has($key), 404);

        $plan->done($vehicle, $key);
        // Its notification no longer applies: the bell shows that at once.
        $notifications->sync($vehicle->fresh());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name done.', ['name' => __(config("maintenance_plan.components.{$key}.label"))])]);

        return back();
    }

    /**
     * Has the AI model propose the intervals of the components.
     */
    public function propose(MaintenancePlanAi $ai): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();

        return $this->whileNotBusy("maintenance-plan:{$vehicle->id}:propose", function () use ($ai, $vehicle) {
            $ai->propose($vehicle, app()->getLocale());

            return __('The intervals have been proposed. Check them, and change what you know better.');
        });
    }

    /**
     * Has the AI model propose the interval of a single component, even one the owner set.
     */
    public function proposeOne(string $key, MaintenancePlanService $plan, MaintenancePlanAi $ai): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();

        abort_unless($plan->components($vehicle)->has($key), 404);

        return $this->whileNotBusy("maintenance-plan:{$vehicle->id}:propose", function () use ($ai, $vehicle, $key) {
            $ai->propose($vehicle, app()->getLocale(), only: $key);

            return __('The interval of :name has been proposed.', ['name' => __(config("maintenance_plan.components.{$key}.label"))]);
        });
    }

    /**
     * Has the AI model sort out the tasks of the maintenance history, for the owner to check.
     */
    public function classify(MaintenancePlanAi $ai): RedirectResponse
    {
        $vehicle = CurrentVehicle::get();

        return $this->whileNotBusy("maintenance-plan:{$vehicle->id}:classify", function () use ($ai, $vehicle) {
            $found = count($ai->classify($vehicle));

            return $found
                ? __('Components were found for :count tasks. Check them below.', ['count' => $found])
                : __('No components were found in the tasks of the maintenance history.');
        });
    }

    /**
     * Stores the components of the tasks as the owner checked them.
     */
    public function assign(Request $request, MaintenancePlanAi $ai): RedirectResponse
    {
        $data = $request->validate([
            'assignments' => ['present', 'array'],
            'assignments.*.task' => ['required', 'string'],
            'assignments.*.components' => ['present', 'array'],
            'assignments.*.components.*' => ['string', Rule::in(array_keys(config('maintenance_plan.components')))],
        ]);

        $changed = $ai->assign(CurrentVehicle::get(), collect($data['assignments'])->pluck('components', 'task')->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':count tasks have been linked to components.', ['count' => $changed])]);

        return back();
    }

    /**
     * Asks the model unless that is going on already, with a toast of how it went.
     *
     * @param  callable(): string  $ask  What to do; the message when it is done.
     */
    private function whileNotBusy(string $key, callable $ask): RedirectResponse
    {
        if (! Cache::add($key, true, self::BUSY_FOR)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('This is being done already. Please wait a moment.')]);

            return back();
        }

        try {
            Inertia::flash('toast', ['type' => 'success', 'message' => $ask()]);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('The AI model could not be reached. Please try again later.')]);
        } finally {
            Cache::forget($key);
        }

        return back();
    }
}
