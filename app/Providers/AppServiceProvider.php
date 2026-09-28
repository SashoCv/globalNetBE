<?php

namespace App\Providers;

use App\Services\CPay\CPayChecksum;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The checksum key and mode come from config, so the service cannot be
        // auto-resolved from its constructor signature.
        $this->app->singleton(CPayChecksum::class, fn () => CPayChecksum::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
