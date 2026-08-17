<?php

namespace App\Http\Controllers;

use App\Services\SteadfastWebhookService;
use Illuminate\Http\Request;

class PublicSteadfastWebhookController extends Controller
{
    /**
     * Steadfast merchant webhook (Bearer token).
     *
     * Configure in Steadfast dashboard:
     *   Callback URL: https://{API_HOST}/api/public/steadfast/webhook/{tenant_id}
     *   or           https://{API_HOST}/api/public/steadfast/webhook
     *   Auth Token:  STEADFAST_WEBHOOK_BEARER
     */
    public function __invoke( Request $request, $tenant = null )
    {
        if ( $request->isMethod( 'get' ) ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Steadfast webhook endpoint is active.',
            ] );
        }

        $secret = SteadfastWebhookService::webhookBearer();
        if ( $secret !== '' && ! $this->bearerMatches( $request, $secret ) ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Invalid Steadfast webhook token.',
            ], 401 );
        }

        $tenantId = $tenant
            ?: $request->query( 'tenant' )
            ?: $request->query( 'tenant_id' )
            ?: $request->input( 'tenant_id' );

        $result = SteadfastWebhookService::handle( $request->all(), $tenantId ? (string) $tenantId : null );

        return response()->json( [
            'status'   => (int) $result['http'],
            'message'  => $result['message'],
            'action'   => $result['action'] ?? null,
            'order_id' => $result['order_id'] ?? null,
        ], (int) $result['http'] );
    }

    private function bearerMatches( Request $request, string $secret ): bool
    {
        $candidates = [
            (string) $request->header( 'Authorization', '' ),
            (string) $request->header( 'X-Steadfast-Token', '' ),
            (string) $request->header( 'X-Webhook-Token', '' ),
            (string) $request->query( 'token', '' ),
        ];

        foreach ( $candidates as $candidate ) {
            $candidate = trim( $candidate );
            if ( $candidate === '' ) {
                continue;
            }
            if ( stripos( $candidate, 'Bearer ' ) === 0 ) {
                $candidate = trim( substr( $candidate, 7 ) );
            }
            if ( hash_equals( $secret, $candidate ) ) {
                return true;
            }
            if ( hash_equals( 'Bearer ' . $secret, $candidate ) ) {
                return true;
            }
        }

        return false;
    }
}
