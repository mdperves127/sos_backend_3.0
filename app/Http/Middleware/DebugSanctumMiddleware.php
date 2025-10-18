<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class DebugSanctumMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            \Log::info('DebugSanctumMiddleware: Starting authentication check');

            // Simple check without accessing user to avoid memory issues
            $user = $request->user();

            if ($user) {
                \Log::info('DebugSanctumMiddleware: User is authenticated - ID: ' . $user->id);
                return $next($request);
            } else {
                \Log::info('DebugSanctumMiddleware: User is not authenticated');
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'status' => 401
                ], 401);
            }
        } catch (\Exception $e) {
            \Log::error('DebugSanctumMiddleware error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Authentication middleware error: ' . $e->getMessage(),
                'status' => 500
            ], 500);
        }
    }
}
