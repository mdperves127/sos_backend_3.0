<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
class ApiAdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // ✅ CRITICAL: Force admin routes to use base MySQL database
        DB::setDefaultConnection('mysql');
        config(['database.default' => 'mysql']);

        // Clear any tenant context completely
        if (function_exists('tenancy')) {
            tenancy()->end();
        }

        // Force Auth to use central database only
        Auth::shouldUse('sanctum');

        // Get user from request (set by adminAuth middleware)
        $user = $request->user();


        if ($user && isset($user->id)) {
            try {
                // User should already be fetched by SimpleSanctumMiddleware
                // Just check if they are admin
                if ($user->role_as == '1') {
                    return $next($request);
                } else {
                    return response()->json([
                        'message' => 'Access Denied.! As you are not an Admin.',
                    ], 403);
                }
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Authentication error: ' . $e->getMessage(),
                ], 500);
            }
        } else {
            return response()->json([
                'status' => 401,
                'message' => 'Please Login First.',
            ]);
        }
    }
}
