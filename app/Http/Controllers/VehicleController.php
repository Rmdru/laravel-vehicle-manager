<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\VehicleStatus;
use App\Forms\VehicleForm;
use App\Http\Middleware\ResolveCurrentVehicle;
use App\Models\Vehicle;
use App\Services\RdwService;
use App\Services\VehicleStatusService;
use App\Support\CurrentVehicle;
use App\Support\Html;
use App\Support\StatusNotification;
use App\Support\VehiclePresenter;
use App\Traits\Vehicles;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TranquilTools\FormBuilder\Fields\Hidden;

class VehicleController extends Controller
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function index(Request $request, VehicleStatusService $statusService): Response
    {
        $vehicles = $request->user()->vehicles()->orderByDesc('purchase_date')->get();

        return Inertia::render('Vehicles/Index', [
            'vehicles' => $vehicles->map(fn (Vehicle $vehicle) => VehiclePresenter::card($vehicle))->all(),
            'createUrl' => route('vehicles.create'),
            // Deferred: working out the notifications can involve slow external lookups.
            'badges' => Inertia::defer(fn () => $vehicles
                ->mapWithKeys(function (Vehicle $vehicle) use ($statusService) {
                    $badge = $statusService->getBadge($vehicle);

                    return [$vehicle->id => $badge ? [
                        'label' => $badge['badgeText'],
                        'color' => $badge['filamentColor'],
                        'icon' => $badge['icon'] ?? null,
                    ] : null];
                })
                ->all()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Crud/Form', [
            'form' => VehicleForm::make()
                ->action(route('vehicles.store'))
                ->method('POST'),
            'title' => __('Add a vehicle'),
            'indexUrl' => route('vehicles.index'),
            'indexTitle' => __('My garage'),
            'variant' => 'vehicle',
            'rdwLookupUrl' => route('vehicles.rdw-lookup'),
            'intro' => CurrentVehicle::get()
                ? null
                : __('To make use of the vehicle manager you need to create your first vehicle.'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(VehicleForm::rules());

        $vehicle = Vehicle::create([...$this->attributes($data), 'user_id' => $request->user()->id]);

        $this->storeImage($vehicle, $request->file('image'));
        $request->session()->put(ResolveCurrentVehicle::SESSION_KEY, $vehicle->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => __('Vehicle')])]);

        return to_route('vehicles.index');
    }

    public function show(Vehicle $vehicle): Response
    {
        $this->authorizeOwner($vehicle);

        $summary = VehiclePresenter::summary($vehicle);
        $status = VehicleStatus::tryFrom((string) $vehicle->status) ?? VehicleStatus::Drivable;
        $fuelTypes = trans('fuel_types');
        $unknown = __('Unknown');

        return Inertia::render('Crud/Show', [
            'title' => $vehicle->full_name,
            'indexUrl' => route('vehicles.index'),
            'indexTitle' => __('My garage'),
            'editUrl' => route('vehicles.edit', $vehicle),
            'image' => $summary['imageUrl'],
            'licensePlate' => $summary['licensePlate'],
            'sections' => [
                ['title' => __('Basic'), 'icon' => 'gmdi-directions-car-filled-r', 'items' => [
                    ['label' => __('Brand'), 'value' => $summary['brand'], 'logo' => $summary['brandLogo']],
                    ['label' => __('Model'), 'value' => $vehicle->model, 'icon' => 'gmdi-directions-car-filled-r'],
                    ['label' => __('Version'), 'value' => $vehicle->version, 'icon' => 'gmdi-star'],
                    ['label' => __('Engine'), 'value' => $vehicle->engine ?: $unknown, 'icon' => 'mdi-engine'],
                    ['label' => __('Powertrain'), 'value' => trans('powertrains')[$vehicle->powertrain]['name'] ?? $unknown, 'icon' => 'gmdi-local-gas-station'],
                    ['label' => __('Compatible fuel types'), 'html' => collect($vehicle->fuel_types)
                        ->map(fn ($type) => Html::badge($fuelTypes[$type] ?? (string) $type))
                        ->implode(' ')],
                ]],
                ['title' => __('Ownership'), 'items' => [
                    ['label' => __('Country of registration'), 'html' => Html::flag($vehicle->country_registration, showName: true)],
                    ['label' => __('Mileage on purchase'), 'value' => filled($vehicle->mileage_start) ? $vehicle->mileage_start.' km' : $unknown, 'icon' => 'gmdi-route'],
                    ['label' => __('Mileage'), 'value' => filled($summary['mileage']) ? $summary['mileage'].' km' : $unknown, 'icon' => 'gmdi-route'],
                    ['label' => __('Purchase date'), 'value' => $vehicle->purchase_date?->isoFormat('ll') ?? $unknown, 'icon' => 'gmdi-calendar-month-r'],
                    ['label' => __('Construction date'), 'value' => $vehicle->construction_date?->isoFormat('ll') ?? $unknown, 'icon' => 'fas-industry'],
                    ['label' => __('Purchase price'), 'value' => filled($vehicle->purchase_price) ? '€ '.number_format((float) $vehicle->purchase_price, 2, ',', '.') : $unknown, 'icon' => 'gmdi-local-offer-r'],
                    ['label' => __('Status'), 'html' => Html::badge($status->getLabel(), icon: $status->getIcon())],
                ]],
                ['title' => __('Specifications'), 'icon' => 'mdi-engine', 'items' => collect($vehicle->specifications ?? [])
                    ->filter(fn ($specification) => filled($specification['name'] ?? null))
                    ->map(fn ($specification) => [
                        'label' => (string) $specification['name'],
                        'icon' => $specification['icon'] ?? null,
                        'value' => is_bool($specification['value'] ?? null)
                            ? ($specification['value'] ? __('Yes') : __('No'))
                            : (string) ($specification['value'] ?? ''),
                    ])
                    ->values()
                    ->all()],
            ],
        ]);
    }

    public function edit(Vehicle $vehicle): Response
    {
        $this->authorizeOwner($vehicle);

        return Inertia::render('Crud/Form', [
            // Sent as POST with a spoofed method: PHP only parses file uploads on POST requests.
            'form' => VehicleForm::make()
                ->fields([Hidden::make('_method')->default('PATCH')])
                ->fill($this->formData($vehicle))
                ->action(route('vehicles.update', $vehicle))
                ->method('POST'),
            'title' => __('Edit :name', ['name' => $vehicle->full_name]),
            'indexUrl' => route('vehicles.index'),
            'indexTitle' => __('My garage'),
            'destroyUrl' => route('vehicles.destroy', $vehicle),
            'variant' => 'vehicle',
            'rdwLookupUrl' => route('vehicles.rdw-lookup'),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeOwner($vehicle);

        $vehicle->update($this->attributes($request->validate(VehicleForm::rules())));

        $this->storeImage($vehicle, $request->file('image'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name updated.', ['name' => __('Vehicle')])]);

        return to_route('vehicles.index');
    }

    public function destroy(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeOwner($vehicle);

        $vehicle->delete();

        if ($request->session()->get(ResolveCurrentVehicle::SESSION_KEY) === $vehicle->id) {
            $request->session()->forget(ResolveCurrentVehicle::SESSION_KEY);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => __('Vehicle')])]);

        return to_route('vehicles.index');
    }

    /**
     * Make the given vehicle the one the rest of the app works with.
     */
    public function select(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorizeOwner($vehicle);

        $request->session()->put(ResolveCurrentVehicle::SESSION_KEY, $vehicle->id);

        return to_route('dashboard');
    }

    public function image(Vehicle $vehicle): StreamedResponse
    {
        $this->authorizeOwner($vehicle);

        abort_unless($vehicle->image_exists, 404);

        return Storage::disk('private')->response($vehicle->image_path);
    }

    /**
     * Values for the vehicle form, based on the RDW registration of a Dutch license plate.
     */
    public function rdwLookup(Request $request, RdwService $rdwService): JsonResponse
    {
        $licensePlate = Vehicles::normalizeLicensePlate((string) $request->query('license_plate'));

        if ($licensePlate === '') {
            return response()->json([]);
        }

        $vehicleData = json_decode($rdwService->fetchVehicleDataByLicensePlate($licensePlate), true)[0] ?? null;

        if (! $vehicleData) {
            return response()->json([]);
        }

        $fuelData = json_decode($rdwService->fetchFuelDataByLicensePlate($licensePlate), true)[0] ?? [];
        $date = fn (?string $value) => $value ? Carbon::createFromFormat('Ymd', $value)->format('Y-m-d') : null;

        $engine = collect([
            ! empty($vehicleData['cilinderinhoud']) ? $vehicleData['cilinderinhoud'].' cc' : null,
            ! empty($vehicleData['aantal_cilinders']) ? $vehicleData['aantal_cilinders'].' '.__('cylinder') : null,
            ! empty($fuelData['nettomaximumvermogen']) ? ((int) $fuelData['nettomaximumvermogen']).' kW' : null,
        ])->filter()->implode(' ');

        $brand = array_search(ucfirst(strtolower($vehicleData['merk'] ?? '')), config('vehicles.brands'), true);
        $powertrain = $rdwService->getPowertrainOptionFromRdwFuelResponse(strtolower($fuelData['brandstof_omschrijving'] ?? ''));

        return response()->json(array_filter([
            'brand' => $brand === false ? null : $brand,
            'model' => ucfirst(strtolower($vehicleData['handelsbenaming'] ?? '')),
            'version' => ucfirst(strtolower($vehicleData['type'] ?? '')),
            'engine' => $engine,
            'purchase_date' => $date($vehicleData['datum_tenaamstelling'] ?? null),
            'construction_date' => $date($vehicleData['datum_eerste_toelating'] ?? null),
            'country_registration' => 'netherlands',
            'powertrain' => $powertrain,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    private function authorizeOwner(Vehicle $vehicle): void
    {
        abort_unless($vehicle->user_id === auth()->id(), 404);
    }

    /**
     * Turn validated form data into vehicle attributes: the flat notification
     * checkboxes become the nested settings and the upload is handled separately.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $notifications = [];

        foreach (StatusNotification::configuration() as $key => $notification) {
            $field = VehicleForm::notificationField($notification['category'], $key);
            $notifications[$notification['category']][$key] = (bool) ($data[$field] ?? true);
            unset($data[$field]);
        }

        unset($data['image'], $data['_method']);

        return [...$data, 'notifications' => $notifications];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Vehicle $vehicle): array
    {
        $data = $vehicle->only([
            'license_plate', 'brand', 'model', 'version', 'engine', 'powertrain', 'fuel_types', 'tank_capacity',
            'maintenance_interval_distance', 'maintenance_interval_time', 'mileage_start', 'purchase_price',
            'country_registration', 'status', 'specifications',
        ]);

        $data['purchase_date'] = $vehicle->purchase_date?->format('Y-m-d');
        $data['construction_date'] = $vehicle->construction_date?->format('Y-m-d');

        foreach (StatusNotification::configuration() as $key => $notification) {
            $category = $notification['category'];

            $data[VehicleForm::notificationField($category, $key)] = (bool) ($vehicle->notifications[$category][$key] ?? true);
        }

        return $data;
    }

    private function storeImage(Vehicle $vehicle, ?UploadedFile $image): void
    {
        if (! $image) {
            return;
        }

        $disk = Storage::disk('private');

        // A vehicle has a single image, named after its id.
        foreach (self::IMAGE_EXTENSIONS as $extension) {
            $disk->delete("vehicles/{$vehicle->id}.{$extension}");
        }

        $extension = strtolower($image->extension() ?: $image->getClientOriginalExtension());

        $image->storeAs('vehicles', "{$vehicle->id}.{$extension}", 'private');
    }
}
