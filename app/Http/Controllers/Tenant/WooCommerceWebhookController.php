<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\WooCommerceSyncJob;
use App\Models\WoocommerceCredential;
use App\Services\WooCommerce\WooCommerceOrderSyncService;
use App\Services\WooCommerce\WooCommerceProductSyncService;
use App\Services\WooCommerce\WooCommerceStockSyncService;
use App\Services\WooCommerce\WooCommerceSyncLogger;
use App\Services\WooCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WooCommerceWebhookController extends Controller {
    public function __construct(
        protected WooCommerceService $woo,
        protected WooCommerceProductSyncService $products,
        protected WooCommerceOrderSyncService $orders,
        protected WooCommerceStockSyncService $stock
    ) {
    }

    public function handle( Request $request ): JsonResponse {
        if ( ! function_exists( 'tenant' ) || ! tenant() ) {
            return response()->json( ['status' => 400, 'message' => 'Tenant context required'], 400 );
        }

        $credential = WoocommerceCredential::query()
            ->where( 'is_active', true )
            ->latest( 'id' )
            ->first();

        if ( ! $credential || ! $credential->auto_sync ) {
            return response()->json( ['status' => 200, 'message' => 'Ignored'] );
        }

        $raw       = $request->getContent();
        $signature = $request->header( 'X-WC-Webhook-Signature' );

        if ( ! WooCommerceService::verifyWebhookSignature( $raw, $signature, $credential->webhook_secret ) ) {
            Log::warning( 'woo.webhook_signature_invalid', ['tenant' => tenant( 'id' )] );

            return response()->json( ['status' => 401, 'message' => 'Invalid signature'], 401 );
        }

        $topic   = (string) $request->header( 'X-WC-Webhook-Topic', '' );
        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            return response()->json( ['status' => 422, 'message' => 'Invalid payload'], 422 );
        }

        try {
            match ( true ) {
                str_starts_with( $topic, 'product.created' ),
                str_starts_with( $topic, 'product.updated' ) => $this->handleProduct( $credential, $payload ),
                str_starts_with( $topic, 'product.deleted' ) => $this->products->deleteLocalByWcId( $credential, (int) ( $payload['id'] ?? 0 ) ),
                str_starts_with( $topic, 'order.created' ),
                str_starts_with( $topic, 'order.updated' ) => $this->orders->importFromWoo( $credential, $payload ),
                default => WooCommerceSyncLogger::log(
                    $credential,
                    'manual',
                    WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
                    'success',
                    'Unhandled webhook topic: ' . $topic
                ),
            };
        } catch ( \Throwable $e ) {
            Log::error( 'woo.webhook_failed', ['topic' => $topic, 'error' => $e->getMessage()] );

            // Queue retry instead of failing the webhook delivery permanently.
            WooCommerceSyncJob::dispatch(
                (string) tenant( 'id' ),
                str_contains( $topic, 'order' ) ? 'order' : 'product',
                [
                    'manual'        => true,
                    'wc_product_id' => $payload['id'] ?? null,
                    'wc_order_id'   => $payload['id'] ?? null,
                    'wc_product'    => str_contains( $topic, 'product' ) ? $payload : null,
                    'wc_order'      => str_contains( $topic, 'order' ) ? $payload : null,
                ],
                $credential->id
            );

            return response()->json( ['status' => 200, 'message' => 'Queued for retry'] );
        }

        return response()->json( ['status' => 200, 'message' => 'OK'] );
    }

    protected function handleProduct( WoocommerceCredential $credential, array $payload ): void {
        $this->products->importFromWoo( $credential, $payload );
        $this->stock->applyFromWoo( $credential, $payload );
    }
}
