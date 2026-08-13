<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyFromRoute
{
    public function handle( Request $request, Closure $next ): Response
    {
        $tenantId = $request->route( 'tenant' );
        $tenant   = $tenantId ? Tenant::find( $tenantId ) : null;

        if ( ! $tenant ) {
            abort( 404, 'Tenant not found for EPS callback.' );
        }

        tenancy()->initialize( $tenant );

        return $next( $request );
    }
}
