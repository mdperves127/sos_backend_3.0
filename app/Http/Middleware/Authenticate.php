<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // For API routes, return JSON response instead of redirecting
        if ($request->is('api/*') || $request->expectsJson()) {
            return null; // Let the parent class handle JSON response
        }

        return '/login'; // For web routes
    }
}
