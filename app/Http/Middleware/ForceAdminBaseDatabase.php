<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ForceAdminBaseDatabase
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // ✅ CRITICAL: Force ALL admin routes to use base MySQL database
            DB::setDefaultConnection('mysql');
            config(['database.default' => 'mysql']);

            // Don't call tenancy()->end() as it might interfere with Sanctum
            // Don't interfere with Sanctum's authentication process

            return $next($request);
        } catch (\Exception $e) {
            // If middleware fails, log the error and continue without it
            \Log::error('ForceAdminBaseDatabase middleware error: ' . $e->getMessage());
            return $next($request);
        }
    }
}
