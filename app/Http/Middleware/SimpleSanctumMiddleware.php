<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SimpleSanctumMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Get the bearer token
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'message' => 'Unauthenticated - No token provided.',
                    'status' => 401
                ], 401);
            }

            // For now, just check if token exists and has correct format
            $tokenParts = explode('|', $token);
            if (count($tokenParts) !== 2) {
                return response()->json([
                    'message' => 'Unauthenticated - Invalid token format.',
                    'status' => 401
                ], 401);
            }

            $tokenId = $tokenParts[0];
            $tokenValue = $tokenParts[1];

            // Just check if token ID is numeric (basic validation)
            if (!is_numeric($tokenId)) {
                return response()->json([
                    'message' => 'Unauthenticated - Invalid token ID.',
                    'status' => 401
                ], 401);
            }

            // Find the actual token in the database
            $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::find($tokenId);

            if (!$personalAccessToken) {
                return response()->json([
                    'message' => 'Unauthenticated - Token not found.',
                    'status' => 401
                ], 401);
            }

            // Verify the token value
            if (!hash_equals($personalAccessToken->token, hash('sha256', $tokenValue))) {
                return response()->json([
                    'message' => 'Unauthenticated - Invalid token.',
                    'status' => 401
                ], 401);
            }

            // Get the actual user from the database
            $user = $personalAccessToken->tokenable;

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthenticated - User not found.',
                    'status' => 401
                ], 401);
            }

            // Set the user in the request
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            // Also set the user in the Auth facade for compatibility
            Auth::setUser($user);

            return $next($request);

        } catch (\Exception $e) {
            \Log::error('SimpleSanctumMiddleware error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Authentication middleware error: ' . $e->getMessage(),
                'status' => 500
            ], 500);
        }
    }
}
