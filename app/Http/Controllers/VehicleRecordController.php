<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\NotificationCenter;
use App\Support\CurrentVehicle;
use App\Support\Navigation;
use App\Tables\VehicleRecordTable;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use TranquilTools\FormBuilder\AbstractForm;

/**
 * CRUD for a type of record that belongs to the current vehicle.
 */
abstract class VehicleRecordController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    /** @var class-string<AbstractForm> */
    protected string $form;

    /** @var class-string<VehicleRecordTable> */
    protected string $table;

    protected string $routeName;

    /** Attribute that is reset to "now" when a record is duplicated. */
    protected string $dateAttribute = 'date';

    /** Column the average and range below the table are calculated from. */
    protected ?string $priceColumn = 'price';

    protected bool $hasShowPage = false;

    abstract protected function title(): string;

    abstract protected function singularTitle(): string;

    protected function description(): ?string
    {
        return null;
    }

    /**
     * The icon shown next to the title: the one of the navigation item.
     */
    protected function icon(): ?string
    {
        $href = route($this->routeName.'.index', absolute: false);

        return collect(Navigation::groups())
            ->flatMap(fn (array $group) => $group['items'])
            ->firstWhere('href', $href)['icon'] ?? null;
    }

    public function index(Request $request): Response
    {
        $linkRoute = $this->routeName.($this->hasShowPage ? '.show' : '.edit');

        $table = new $this->table;

        return Inertia::render('Crud/Index', [
            'table' => $table->make()->beforeRender()->rowLink(fn (Model $record) => route($linkRoute, $record->getKey())),
            'title' => $this->title(),
            'icon' => $this->icon(),
            'description' => $this->description(),
            'showable' => $this->hasShowPage,
            'baseUrl' => route($this->routeName.'.index'),
            'createUrl' => route($this->routeName.'.create'),
            'summary' => $this->summary($table->filteredQuery()),
            'filters' => $request->only(['date_from', 'date_until']),
            ...$this->indexProps(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Crud/Form', [
            'form' => $this->form::make()
                ->fill($this->createData(request()))
                ->action(route($this->routeName.'.store'))
                ->method('POST'),
            'title' => str(__('Create :name', ['name' => mb_strtolower($this->singularTitle())]))->ucfirst()->toString(),
            'indexUrl' => route($this->routeName.'.index'),
            'indexTitle' => $this->title(),
            ...$this->formProps(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->prepare($request->validate($this->form::rules()), null);

        $record = $this->model::create([...$data, 'vehicle_id' => $this->vehicle()->id]);

        $this->saved($record);
        $this->refreshNotifications();

        return $this->redirectToIndex(__(':name created.', ['name' => $this->singularTitle()]));
    }

    public function edit(string $id): Response
    {
        $record = $this->record($id);

        return Inertia::render('Crud/Form', [
            'form' => $this->form::make()
                ->fill($this->formData($record))
                ->action(route($this->routeName.'.update', $record->getKey()))
                ->method('PATCH'),
            'title' => str(__('Edit :name', ['name' => mb_strtolower($this->singularTitle())]))->ucfirst()->toString(),
            'indexUrl' => route($this->routeName.'.index'),
            'indexTitle' => $this->title(),
            'destroyUrl' => route($this->routeName.'.destroy', $record->getKey()),
            ...$this->formProps(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $record = $this->record($id);

        $record->update($this->prepare($request->validate($this->form::rules()), $record));

        $this->saved($record);
        $this->refreshNotifications();

        return $this->redirectToIndex(__(':name updated.', ['name' => $this->singularTitle()]));
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->record($id)->delete();
        $this->refreshNotifications();

        return $this->redirectToIndex(__(':name deleted.', ['name' => $this->singularTitle()]));
    }

    /**
     * Brings the notifications of the vehicle up to date after the response:
     * a record can make one apply or no longer apply, and the bell shows them.
     */
    protected function refreshNotifications(): void
    {
        $vehicle = $this->vehicle();

        defer(fn () => app(NotificationCenter::class)->sync($vehicle->fresh()));
    }

    public function duplicate(string $id): RedirectResponse
    {
        $replica = $this->record($id)->replicate();
        $replica->{$this->dateAttribute} = now();
        $replica->save();

        return $this->redirectToIndex(__(':name duplicated.', ['name' => $this->singularTitle()]));
    }

    protected function vehicle(): Vehicle
    {
        return CurrentVehicle::get() ?? abort(404);
    }

    protected function record(string $id): Model
    {
        return $this->model::query()
            ->where('vehicle_id', $this->vehicle()->id)
            ->findOrFail($id);
    }

    /**
     * Adjust the validated form data before it is stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepare(array $data, ?Model $record): array
    {
        return $data;
    }

    protected function saved(Model $record): void
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    protected function indexProps(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formProps(): array
    {
        return [];
    }

    /**
     * The record's values in the shape the form fields expect. Deliberately not
     * the model itself: serializing it would also evaluate its appended attributes.
     *
     * @return array<string, mixed>
     */
    /**
     * Values a new record starts with instead of the defaults of the form.
     *
     * @return array<string, mixed>
     */
    protected function createData(Request $request): array
    {
        return [];
    }

    protected function formData(Model $record): array
    {
        return collect($record->getFillable())
            ->mapWithKeys(fn (string $attribute) => [$attribute => $this->formValue($record->{$attribute})])
            ->all();
    }

    protected function formValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
            $value instanceof BackedEnum => $value->value,
            default => $value,
        };
    }

    /**
     * Figures below the table, over the records the table currently lists.
     *
     * @return list<array{label: string, value: string}>
     */
    protected function summary(Builder $query): array
    {
        if (! $this->priceColumn) {
            return [];
        }

        $stats = (clone $query)
            ->selectRaw("AVG({$this->priceColumn}) as average, MIN({$this->priceColumn}) as minimum, MAX({$this->priceColumn}) as maximum")
            ->toBase()
            ->first();

        if ($stats?->average === null) {
            return [];
        }

        $money = fn ($value) => '€ '.number_format((float) $value, 2, ',', '.');

        return [
            ['label' => __('Price average'), 'value' => $money($stats->average)],
            ['label' => __('Price range'), 'value' => $money($stats->minimum).' - '.$money($stats->maximum)],
        ];
    }

    protected function redirectToIndex(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route($this->routeName.'.index');
    }
}
