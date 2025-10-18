<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class CustomSanctumMiddleware
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

            // Parse the token
            $tokenParts = explode('|', $token);
            if (count($tokenParts) !== 2) {
                return response()->json([
                    'message' => 'Unauthenticated - Invalid token format.',
                    'status' => 401
                ], 401);
            }

            $tokenId = $tokenParts[0];
            $tokenValue = $tokenParts[1];

            // Find the token in the database
            $personalAccessToken = PersonalAccessToken::find($tokenId);
            if (!$personalAccessToken) {
                return response()->json([
                    'message' => 'Unauthenticated - Token not found.',
                    'status' => 401
                ], 401);
            }

            // Verify the token
            if (!hash_equals($personalAccessToken->token, hash('sha256', $tokenValue))) {
                return response()->json([
                    'message' => 'Unauthenticated - Invalid token.',
                    'status' => 401
                ], 401);
            }

            // Check if token is expired
            if ($personalAccessToken->expires_at && $personalAccessToken->expires_at->isPast()) {
                return response()->json([
                    'message' => 'Unauthenticated - Token expired.',
                    'status' => 401
                ], 401);
            }

            // Get the user ID without loading the full user model to avoid memory issues
            $userId = $personalAccessToken->tokenable_id;
            if (!$userId) {
                return response()->json([
                    'message' => 'Unauthenticated - User not found.',
                    'status' => 401
                ], 401);
            }

            // Create a minimal user object with just the ID to avoid memory issues
            $user = new \stdClass();
            $user->id = $userId;

            // Set the user in the request (avoid Auth facade to prevent memory issues)
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            return $next($request);

        } catch (\Exception $e) {
            \Log::error('CustomSanctumMiddleware error: ' . $e->getMessage());
            \Log::error('CustomSanctumMiddleware stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Authentication middleware error: ' . $e->getMessage(),
                'status' => 500
            ], 500);
        }
    }
}
