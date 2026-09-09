<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RateLimiterService::class);
        $this->app->singleton(SupplierService::class);
        $this->app->singleton(OrderQueueService::class);
        $this->app->singleton(MultiOrderService::class, function ($app) {
            return new MultiOrderService(
                $app->make(SupplierService::class),
                $app->make(RateLimiterService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
