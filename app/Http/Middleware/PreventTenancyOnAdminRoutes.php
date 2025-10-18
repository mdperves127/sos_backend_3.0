<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PreventTenancyOnAdminRoutes
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if this is an admin route
        $path = $request->path();
        $isAdminRoute = str_starts_with($path, 'api/admin') ||
                       str_contains($request->url(), '/api/admin');

        // If it's an admin route, ensure we're using the central database
        if ($isAdminRoute) {
            // Force central database connection for admin routes
            config(['database.default' => env('DB_CONNECTION', 'mysql')]);
            \DB::setDefaultConnection(env('DB_CONNECTION', 'mysql'));

            // Clear any tenant context
            if (function_exists('tenancy')) {
                tenancy()->end();
            }

            // Ensure Auth uses central database
            if (function_exists('auth')) {
                auth()->setDefaultDriver('sanctum');
            }
        }

        return $next($request);
    }
}
