<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/admin.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/affiliator.php'));
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/user.php'));
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/vendor.php'));

            // Tenant routes - same API stack as other /api groups (logging + throttle + bindings)
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/tenant.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            $ip = (string) $request->ip();

            if ( $request->user() ) {
                $limit = max( 60, (int) env( 'API_RATE_LIMIT_AUTHENTICATED', 300 ) );

                return Limit::perMinute( $limit )->by( 'user:' . $request->user()->id );
            }

            // Public marketing/storefront GETs are hit by many browsers (and load tests)
            // from a single NAT/proxy IP. 100/min per IP rejects ~90% of that traffic as 429.
            if ( $request->isMethod( 'GET' ) ) {
                $limit = max( 100, (int) env( 'API_RATE_LIMIT_PUBLIC_GET', 12000 ) );

                return Limit::perMinute( $limit )->by( 'ip-get:' . $ip );
            }

            $writeLimit = max( 20, (int) env( 'API_RATE_LIMIT_WRITE', 120 ) );

            return Limit::perMinute( $writeLimit )->by( 'ip-write:' . $ip );
        });
    }
}
