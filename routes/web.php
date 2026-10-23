<?php

use App\Http\Controllers\AccidentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnvironmentalStickerController;
use App\Http\Controllers\FerryController;
use App\Http\Controllers\FineController;
use App\Http\Controllers\FuelAdviceController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MaintenancePlanController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ParkingController;
use App\Http\Controllers\ProductController;
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

Route::middleware(['auth', 'verified'])->group(function () {
    // The garage is reachable without a vehicle, so that the first one can be created.
    Route::get('vehicles/rdw-lookup', [VehicleController::class, 'rdwLookup'])->name('vehicles.rdw-lookup');
    Route::get('vehicles/{vehicle}/image', [VehicleController::class, 'image'])->name('vehicle.image');
    Route::post('vehicles/{vehicle}/select', [VehicleController::class, 'select'])->name('vehicles.select');
    Route::resource('vehicles', VehicleController::class);

    Route::middleware(CreateFirstVehicle::class)->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('timeline', TimelineController::class)->name('timeline');
        Route::get('tips', [VehicleAdviceController::class, 'index'])->name('tips');
        Route::post('fuel-prices/refresh', [DashboardController::class, 'refreshFuelPrices'])->name('fuel-prices.refresh');
        Route::post('fuel-advice', [FuelAdviceController::class, 'store'])->name('fuel-advice.store');
        Route::post('vehicle-advice', [VehicleAdviceController::class, 'store'])->name('vehicle-advice.store');
        Route::post('vehicle-advice/status', [VehicleAdviceController::class, 'status'])->name('vehicle-advice.status');

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

        $records = [
            'maintenances' => MaintenanceController::class,
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
