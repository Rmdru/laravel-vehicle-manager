<?php

namespace App\Providers;

use App\Models\Accident;
use App\Models\EnvironmentalSticker;
use App\Models\Ferry;
use App\Models\Fine;
use App\Models\Insurance;
use App\Models\Maintenance;
use App\Models\Parking;
use App\Models\Product;
use App\Models\Reconditioning;
use App\Models\Refueling;
use App\Models\Service;
use App\Models\Tax;
use App\Models\Toll;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vignette;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->flushCachedStatisticsOnChanges();
        $this->rememberLastLogin();
    }

    /**
     * The AI features are only generated for users who logged in lately. Logging in
     * by the remember me cookie counts as well.
     */
    protected function rememberLastLogin(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }

    /**
     * The statistics of a vehicle are cached, so drop them whenever its data changes.
     */
    protected function flushCachedStatisticsOnChanges(): void
    {
        foreach ([
            Accident::class,
            EnvironmentalSticker::class,
            Ferry::class,
            Fine::class,
            Insurance::class,
            Maintenance::class,
            Parking::class,
            Product::class,
            Reconditioning::class,
            Refueling::class,
            Service::class,
            Tax::class,
            Toll::class,
            Vehicle::class,
            Vignette::class,
        ] as $model) {
            $model::saved(fn () => Cache::flush());
            $model::deleted(fn () => Cache::flush());
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
