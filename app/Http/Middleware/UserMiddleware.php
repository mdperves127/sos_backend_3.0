<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserMiddleware {
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle( Request $request, Closure $next ) {
        try {
            // Ensure database connection is set first
            \DB::setDefaultConnection('mysql');

            // Get user from request (set by auth:sanctum middleware)
            $user = $request->user();

            // If user is not set, try to authenticate using the token
            if ( !$user || !isset($user->id) ) {
                $token = $request->bearerToken();

                if (!$token) {
                    return response()->json( [
                        'status'  => 401,
                        'message' => 'Please Login First.',
                    ], 401 );
                }

                // Parse the token
                $tokenParts = explode('|', $token);
                if (count($tokenParts) !== 2) {
                    return response()->json( [
                        'status'  => 401,
                        'message' => 'Invalid token format.',
                    ], 401 );
                }

                $tokenId = $tokenParts[0];
                $tokenValue = $tokenParts[1];

                // Find the token in the database
                $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::find($tokenId);

                if (!$personalAccessToken) {
                    return response()->json( [
                        'status'  => 401,
                        'message' => 'Token not found.',
                    ], 401 );
                }

                // Verify the token
                if (!hash_equals($personalAccessToken->token, hash('sha256', $tokenValue))) {
                    return response()->json( [
                        'status'  => 401,
                        'message' => 'Invalid token.',
                    ], 401 );
                }

                // Get the user
                $user = $personalAccessToken->tokenable;

                if (!$user) {
                    return response()->json( [
                        'status'  => 401,
                        'message' => 'User not found.',
                    ], 401 );
                }

                // Set the user in the request for other middleware/controllers
                $request->setUserResolver(function () use ($user) {
                    return $user;
                });

                \Auth::setUser($user);
            }

            // Check if required properties exist
            $roleAs = $user->role_as ?? null;
            $status = $user->status ?? null;
            $emailVerifiedAt = $user->email_verified_at ?? null;

            if ( $roleAs == '4' ) {
                if ( $status == 'active' && $emailVerifiedAt != null ) {
                    return $next( $request );
                } elseif ( $status == 'blocked' ) {
                    return response()->json( [
                        'status'  => 403,
                        'message' => 'Account is blocked. please contect with admin !',
                    ], 403 );
                } else {
                    return response()->json( [
                        'status'  => 403,
                        'message' => 'Account is not active',
                    ], 403 );
                }
            } else {
                return response()->json( [
                    'status'  => 403,
                    'message' => 'Access Denied.! As you are not an User.',
                ], 403 );
            }
        } catch (\Throwable $e) {
            \Log::error('UserMiddleware error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
            return response()->json( [
                'status'  => 500,
                'message' => 'Authentication error occurred',
            ], 500 );
        }
    }
}
