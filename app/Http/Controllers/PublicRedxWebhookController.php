<?php

namespace App\Http\Controllers;

use App\Services\RedxWebhookService;
use Illuminate\Http\Request;

class PublicRedxWebhookController extends Controller
{
    /**
     * RedX merchant webhook.
     *
     * Configure in RedX dashboard:
     *   Callback URL: https://{API_HOST}/api/public/redx/webhook/{tenant_id}?token={REDX_WEBHOOK_SECRET}
     *   or            https://{API_HOST}/api/public/redx/webhook?token={REDX_WEBHOOK_SECRET}
     *
     * RedX sends credentials in the query string.
     */
    public function __invoke( Request $request, $tenant = null )
    {
        if ( $request->isMethod( 'get' ) ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'RedX webhook endpoint is active.',
            ] );
        }

        $secret = RedxWebhookService::webhookSecret();
        if ( $secret !== '' && ! $this->tokenMatches( $request, $secret ) ) {
            return response()->json( [
                'status'  => 401,
                'message' => 'Invalid RedX webhook token.',
            ], 401 );
        }

        $tenantId = $tenant
            ?: $request->query( 'tenant' )
            ?: $request->query( 'tenant_id' )
            ?: $request->input( 'tenant_id' );

        $result = RedxWebhookService::handle( $request->all(), $tenantId ? (string) $tenantId : null );

        return response()->json( [
            'status'   => (int) $result['http'],
            'message'  => $result['message'],
            'action'   => $result['action'] ?? null,
            'order_id' => $result['order_id'] ?? null,
        ], (int) $result['http'] );
    }

    private function tokenMatches( Request $request, string $secret ): bool
    {
        $candidates = [
            (string) $request->query( 'token', '' ),
            (string) $request->query( 'secret', '' ),
            (string) $request->header( 'X-Redx-Webhook-Secret', '' ),
            (string) $request->header( 'Authorization', '' ),
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
        }

        return false;
    }
}
