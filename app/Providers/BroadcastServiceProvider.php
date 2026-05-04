<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot() {
        // Tenant DM auth lives under /api on the tenant host (see routes/tenant.php). Central apps can add
        // Broadcast::routes(['middleware' => ['web', 'auth:sanctum']]) here if needed.
        require base_path( 'routes/channels.php' );
    }
}
