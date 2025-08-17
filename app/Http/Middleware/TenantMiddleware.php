<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
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
        // ✅ 1️⃣ Check if tenant is resolved
        if (!function_exists('tenant') || !tenant()) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        // ✅ 2️⃣ Check if bearer token is present
        $bearerToken = $request->bearerToken();
        if (!$bearerToken) {
            return response()->json(['message' => 'No bearer token provided.'], 401);
        }

        // ✅ 3️⃣ Parse the token (format: "4|token...")
        $tokenParts = explode('|', $bearerToken);
        if (count($tokenParts) !== 2) {
            return response()->json(['message' => 'Invalid token format.'], 401);
        }

        $tokenId = $tokenParts[0];
        $tokenValue = $tokenParts[1];

        // ✅ 4️⃣ Find the token in the tenant database
        $token = \App\Models\PersonalAccessToken::find($tokenId);
        if (!$token) {
            return response()->json(['message' => 'Token not found.'], 401);
        }

        // ✅ 5️⃣ Verify the token hash
        $expectedHash = hash('sha256', $tokenValue);
        if (!hash_equals($token->token, $expectedHash)) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        // ✅ 6️⃣ Get the user from the tenant database
        $user = \App\Models\User::find($token->tokenable_id);
        if (!$user) {
            return response()->json(['message' => 'User not found in tenant.'], 401);
        }

        // ✅ 7️⃣ Set the user in the request for use in controllers
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        // ✅ 8️⃣ Set the user in Auth facade so Auth::user() works
        Auth::setUser($user);

        return $next($request);
    }
}
