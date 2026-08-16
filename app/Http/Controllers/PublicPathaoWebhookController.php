<?php

namespace App\Http\Controllers;

use App\Services\PathaoWebhookService;
use Illuminate\Http\Request;

class PublicPathaoWebhookController extends Controller
{
    /**
     * Pathao merchant webhook (no auth).
     *
     * Configure in Pathao dashboard:
     *   https://{API_HOST}/api/public/pathao/webhook/{tenant_id}
     * or
     *   https://{API_HOST}/api/public/pathao/webhook
     *
     * Secret: PATHAO_WEBHOOK_SECRET (must match X-PATHAO-Signature / X-Pathao-Signature)
     */
    public function __invoke( Request $request, $tenant = null )
    {
        $secret = PathaoWebhookService::webhookSecret();
        $response = function ( int $http, array $body ) use ( $secret ) {
            return response()
                ->json( $body, $http )
                ->header( 'X-Pathao-Merchant-Webhook-Integration-Secret', $secret );
        };

        if ( $secret !== '' ) {
            $signature = (string) (
                $request->header( 'X-PATHAO-Signature' )
                ?: $request->header( 'X-Pathao-Signature' )
                ?: $request->header( 'x-pathao-signature' )
                ?: ''
            );

            // Handshake may omit signature on some setups; still require secret echo header.
            $event = (string) $request->input( 'event', '' );
            $isHandshake = in_array( $event, ['webhook_integration', 'webhook.integration'], true );

            if ( ! $isHandshake && $signature !== '' && ! hash_equals( $secret, $signature ) ) {
                return $response( 401, [
                    'status'  => 401,
                    'message' => 'Invalid Pathao webhook signature.',
                ] );
            }
        }

        $tenantId = $tenant
            ?: $request->query( 'tenant' )
            ?: $request->query( 'tenant_id' )
            ?: $request->input( 'tenant_id' );

        $result = PathaoWebhookService::handle( $request->all(), $tenantId ? (string) $tenantId : null );

        return $response( (int) $result['http'], [
            'status'   => (int) $result['http'],
            'message'  => $result['message'],
            'action'   => $result['action'] ?? null,
            'order_id' => $result['order_id'] ?? null,
        ] );
    }
}
