<?php

namespace App\Http\Middleware;

use Closure;

class SetTenantDBAsDefault
{
    public function handle($request, Closure $next)
    {
        if (function_exists('tenant') && tenant()) {
            config(['database.default' => 'tenant']);
        }
        return $next($request);
    }
}
