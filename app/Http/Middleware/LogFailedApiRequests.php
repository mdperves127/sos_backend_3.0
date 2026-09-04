<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogFailedApiRequests
{
    public function handle( Request $request, Closure $next ): Response
    {
        $started = microtime( true );

        try {
            $response = $next( $request );
        } catch ( Throwable $e ) {
            $this->write( $request, null, $started, $e );
            throw $e;
        }

        $this->write( $request, $response, $started, null );

        return $response;
    }

    private function write( Request $request, ?Response $response, float $started, ?Throwable $e ): void
    {
        $durationMs = (int) round( ( microtime( true ) - $started ) * 1000 );
        $status     = $response?->getStatusCode() ?? ( $e ? 500 : 0 );

        if ( $status < 400 && $durationMs < 2500 && ! $e ) {
            return;
        }

        $tenantId = null;
        try {
            if ( function_exists( 'tenant' ) && tenant() ) {
                $tenantId = tenant()->id;
            }
        } catch ( Throwable ) {
            $tenantId = null;
        }

        Log::warning( 'api.request', [
            'request_id' => $request->attributes->get( 'request_id' ),
            'method'     => $request->method(),
            'path'       => '/' . ltrim( $request->path(), '/' ),
            'status'     => $status,
            'duration_ms'=> $durationMs,
            'tenant_id'  => $tenantId,
            'user_id'    => $request->user()?->id,
            'exception'  => $e ? class_basename( $e ) : null,
        ] );
    }
}
