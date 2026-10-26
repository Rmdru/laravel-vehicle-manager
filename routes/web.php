<?php

use App\Http\Controllers\AccidentController;
use App\Http\Controllers\CostForecastController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnvironmentalStickerController;
use App\Http\Controllers\FerryController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\FineController;
use App\Http\Controllers\ForTheGarageController;
use App\Http\Controllers\FuelAdviceController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MaintenancePlanController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ParkingController;
use App\Http\Controllers\PowertrainComparisonController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PushMessageController;
use App\Http\Controllers\ReconditioningController;
use App\Http\Controllers\RefuelingController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\TollController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\VehicleAdviceController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VignetteController;
use App\Http\Middleware\CreateFirstVehicle;
use Illuminate\Support\Facades\Route;

Route::post('locale', LocaleController::class)->name('locale.update');
Route::get('manifest.webmanifest', ManifestController::class)->name('manifest');

// A button of a push notification, sent by the service worker without the app
// being open: the signed link stands in for the login and the CSRF token.
Route::post('push/{message}/action/{action}', [PushMessageController::class, 'action'])
    ->middleware(['signed', 'throttle:30,1'])
    ->whereIn('action', ['dismiss', 'done'])
    ->name('push.action');

Route::middleware(['auth', 'verified'])->group(function () {
    // The garage is reachable without a vehicle, so that the first one can be created.
    Route::get('vehicles/rdw-lookup', [VehicleController::class, 'rdwLookup'])->name('vehicles.rdw-lookup');
    Route::get('vehicles/{vehicle}/image', [VehicleController::class, 'image'])->name('vehicle.image');
    Route::post('vehicles/{vehicle}/select', [VehicleController::class, 'select'])->name('vehicles.select');
    Route::resource('vehicles', VehicleController::class);
    // A push notification was tapped.
    Route::get('push/{message}/open', [PushMessageController::class, 'open'])->name('push.open');

    Route::middleware(CreateFirstVehicle::class)->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('timeline', TimelineController::class)->name('timeline');
        Route::get('tips', [VehicleAdviceController::class, 'index'])->name('tips');
        Route::get('cost-forecast', CostForecastController::class)->name('cost-forecast');
        Route::post('fuel-prices/refresh', [DashboardController::class, 'refreshFuelPrices'])->name('fuel-prices.refresh');
        Route::post('fuel-advice', [FuelAdviceController::class, 'store'])->name('fuel-advice.store');
        Route::post('vehicle-advice', [VehicleAdviceController::class, 'store'])->name('vehicle-advice.store');
        Route::post('vehicle-advice/status', [VehicleAdviceController::class, 'status'])->name('vehicle-advice.status');
        Route::post('powertrain-comparison', [PowertrainComparisonController::class, 'store'])->name('powertrain-comparison.store');
        Route::post('powertrain-comparison/refresh', [PowertrainComparisonController::class, 'refresh'])->name('powertrain-comparison.refresh');

        Route::get('trips/geocode', [TripController::class, 'geocode'])->name('trips.geocode');
        Route::get('trips/reverse-geocode', [TripController::class, 'reverseGeocode'])->name('trips.reverse-geocode');
        Route::get('trips/logo', [TripController::class, 'logo'])->name('trips.logo');
        Route::patch('trips/{trip}/route', [TripController::class, 'selectRoute'])->name('trips.route');
        Route::patch('trips/{trip}/stops', [TripController::class, 'stops'])->name('trips.stops');
        Route::post('trips/{trip}/tips', [TripController::class, 'tips'])->name('trips.tips');
        Route::post('trips/{trip}/refresh', [TripController::class, 'refresh'])->name('trips.refresh');
        Route::resource('trips', TripController::class)->except('edit');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/seen', [NotificationController::class, 'seen'])->name('notifications.seen');
        Route::post('notifications/{notification}/dismiss', [NotificationController::class, 'dismiss'])->name('notifications.dismiss');
        Route::post('notifications/{notification}/restore', [NotificationController::class, 'restore'])->name('notifications.restore');

        Route::get('maintenance-plan', [MaintenancePlanController::class, 'index'])->name('maintenance-plan');
        Route::post('maintenance-plan/propose', [MaintenancePlanController::class, 'propose'])->name('maintenance-plan.propose');
        Route::post('maintenance-plan/classify', [MaintenancePlanController::class, 'classify'])->name('maintenance-plan.classify');
        Route::post('maintenance-plan/assign', [MaintenancePlanController::class, 'assign'])->name('maintenance-plan.assign');
        Route::patch('maintenance-plan/{key}', [MaintenancePlanController::class, 'update'])->name('maintenance-plan.update');
        Route::post('maintenance-plan/{key}/done', [MaintenancePlanController::class, 'done'])->name('maintenance-plan.done');
        Route::post('maintenance-plan/{key}/propose', [MaintenancePlanController::class, 'proposeOne'])->name('maintenance-plan.propose-one');

        Route::get('for-the-garage', [ForTheGarageController::class, 'index'])->name('for-the-garage');
        Route::post('for-the-garage/plan/{key}', [ForTheGarageController::class, 'plan'])->name('for-the-garage.plan');
        Route::post('for-the-garage/lines', [ForTheGarageController::class, 'storeLine'])->name('for-the-garage.lines.store');
        Route::delete('for-the-garage/lines/{line}', [ForTheGarageController::class, 'destroyLine'])->name('for-the-garage.lines.destroy');
        Route::post('findings/{record}/garage', [FindingController::class, 'garage'])->name('findings.garage');
        Route::post('findings/{record}/resolve', [FindingController::class, 'resolve'])->name('findings.resolve');
        Route::post('findings/{record}/diagnose', [FindingController::class, 'diagnose'])->name('findings.diagnose');

        $records = [
            'maintenances' => MaintenanceController::class,
            'findings' => FindingController::class,
            'reconditionings' => ReconditioningController::class,
            'accidents' => AccidentController::class,
            'products' => ProductController::class,
            'refuelings' => RefuelingController::class,
            'insurances' => InsuranceController::class,
            'taxes' => TaxController::class,
            'parking' => ParkingController::class,
            'toll' => TollController::class,
            'fines' => FineController::class,
            'vignettes' => VignetteController::class,
            'environmental-stickers' => EnvironmentalStickerController::class,
            'services' => ServiceController::class,
            'ferries' => FerryController::class,
        ];

        foreach ($records as $uri => $controller) {
            Route::get("{$uri}/{record}/duplicate", [$controller, 'duplicateDetails'])->name("{$uri}.duplicate-details");
            Route::post("{$uri}/{record}/duplicate", [$controller, 'duplicate'])->name("{$uri}.duplicate");
            Route::resource($uri, $controller)
                ->parameters([$uri => 'record'])
                ->only(method_exists($controller, 'show')
                    ? ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']
                    : ['index', 'create', 'store', 'edit', 'update', 'destroy']);
        }
    });
});

require __DIR__.'/settings.php';
