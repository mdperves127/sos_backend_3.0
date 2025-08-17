<?php

namespace App\Providers;

use App\Models\Product;
use App\Observers\ProductObserver;
use App\Services\CpanelService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Register CpanelService as a singleton
        $this->app->singleton(CpanelService::class, function ($app) {
            return new CpanelService();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
           Schema::defaultStringLength(191);
           Paginator::useBootstrap();
        //    Product::observe(ProductObserver::class);
    }
}
