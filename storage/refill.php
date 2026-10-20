<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$started = microtime(true);
$log = fn (string $line) => print(round(microtime(true) - $started).'s '.$line."\n");
App\Models\Poi::query()->delete();
App\Models\PoiTile::query()->delete();
$log('cleared');
config(['trip_planner.warm.countries' => array_intersect_key(config('trip_planner.warm.countries'), array_flip(['NL', 'BE', 'LU']))]);
Illuminate\Support\Facades\Artisan::call('trip-planner:warm', ['--blocks' => 100]);
$log(trim(Illuminate\Support\Facades\Artisan::output()));
foreach (App\Models\Trip::all() as $trip) {
    (new App\Jobs\PlanTrip($trip))->handle(app(App\Services\TripPlanner\TripPlanner::class));
    $trip->refresh();
    $log("trip {$trip->id} {$trip->status} ".implode(',', $trip->plan['routes'][0]['warnings'] ?? []));
}
$log(App\Models\PoiTile::count().' tiles, '.App\Models\Poi::count().' places');
