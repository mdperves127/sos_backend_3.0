<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\WooCommerceSyncJob;
use App\Models\WoocommerceCredential;
use App\Models\WoocommerceSyncLog;
use App\Services\WooCommerce\WooCommerceOrderSyncService;
use App\Services\WooCommerce\WooCommerceProductSyncService;
use App\Services\WooCommerce\WooCommerceSyncLogger;
use App\Services\WooCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Merchant WooCommerce integration dashboard API (tenant-scoped).
 */
class WooCommerceIntegrationController extends Controller {
    public function __construct( protected WooCommerceService $woo ) {
    }

    public function show(): JsonResponse {
        $credential = $this->ownedCredential( includeInactive: true );

        return response()->json( [
            'status' => 200,
            'data'   => $credential?->toPublicArray(),
        ] );
    }

    public function connect( Request $request ): JsonResponse {
        $validator = Validator::make( $request->all(), [
            'wc_url'    => ['required', 'url', 'max:255'],
            'wc_key'    => ['required', 'string', 'max:255'],
            'wc_secret' => ['required', 'string', 'max:255'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( ['status' => 422, 'errors' => $validator->errors()], 422 );
        }

        $url    = rtrim( (string) $request->input( 'wc_url' ), '/' );
        $key    = (string) $request->input( 'wc_key' );
        $secret = (string) $request->input( 'wc_secret' );

        // Prevent another tenant/vendor claiming the same store URL inside this tenant DB is fine;
        // globally, URL uniqueness was already in the legacy controller — keep per-tenant uniqueness.
        $current = $this->ownedCredential( includeInactive: true );
        $exists  = WoocommerceCredential::query()
            ->where( 'wc_url', $url )
            ->when( $current, fn ( $q ) => $q->where( 'id', '!=', $current->id ) )
            ->exists();

        if ( $exists ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'This store URL is already registered.',
            ], 422 );
        }

        try {
            $result = $this->woo->validateConnection( $url, $key, $secret );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'status'  => 422,
                'message' => $e->getMessage(),
            ], 422 );
        }

        $credential = $this->ownedCredential() ?: new WoocommerceCredential();
        $credential->fill( [
            'vendor_id'           => vendorId(),
            'wc_url'              => $url,
            'wc_key'              => $key,
            'wc_secret'           => $secret,
            'status'              => WoocommerceCredential::STATUS_CONNECTED,
            'connection_message'  => $result['message'] ?? 'Connected',
            'is_active'           => true,
            'sync_direction'      => $credential->sync_direction ?: WoocommerceCredential::DIRECTION_TWO_WAY,
            'product_sync'        => $credential->product_sync ?? true,
            'order_sync'          => $credential->order_sync ?? true,
            'stock_sync'          => $credential->stock_sync ?? true,
            'auto_sync'           => $credential->auto_sync ?? true,
            'webhook_secret'      => $credential->webhook_secret ?: Str::random( 32 ),
            'last_error'          => null,
        ] );
        $credential->save();

        WooCommerceSyncLogger::log(
            $credential,
            'connection',
            WoocommerceCredential::DIRECTION_TWO_WAY,
            'success',
            'Store connected'
        );

        return response()->json( [
            'status'  => 200,
            'message' => 'WooCommerce store connected.',
            'data'    => $credential->fresh()->toPublicArray(),
            'webhook' => [
                'url'    => url( '/api/woocommerce/webhook' ),
                'secret' => $credential->webhook_secret,
                'topics' => [
                    'product.created', 'product.updated', 'product.deleted',
                    'order.created', 'order.updated',
                ],
            ],
        ] );
    }

    public function disconnect(): JsonResponse {
        $credential = $this->ownedCredential();
        if ( ! $credential ) {
            return response()->json( ['status' => 404, 'message' => 'No connected store.'], 404 );
        }

        $credential->update( [
            'status'             => WoocommerceCredential::STATUS_DISCONNECTED,
            'is_active'          => false,
            'connection_message' => 'Disconnected by merchant',
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Store disconnected.',
            'data'    => $credential->fresh()->toPublicArray(),
        ] );
    }

    public function reconnect( Request $request ): JsonResponse {
        $credential = $this->ownedCredential( includeInactive: true );
        if ( ! $credential ) {
            return response()->json( ['status' => 404, 'message' => 'No store credentials found. Connect first.'], 404 );
        }

        try {
            $this->woo->validateConnection( $credential->wc_url, $credential->wc_key, $credential->wc_secret );
        } catch ( \Throwable $e ) {
            $credential->update( [
                'status'             => WoocommerceCredential::STATUS_ERROR,
                'connection_message' => $e->getMessage(),
                'last_error'         => $e->getMessage(),
            ] );

            return response()->json( ['status' => 422, 'message' => $e->getMessage()], 422 );
        }

        $credential->update( [
            'status'             => WoocommerceCredential::STATUS_CONNECTED,
            'is_active'          => true,
            'connection_message' => 'Reconnected',
            'last_error'         => null,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Store reconnected.',
            'data'    => $credential->fresh()->toPublicArray(),
        ] );
    }

    public function updateSettings( Request $request ): JsonResponse {
        $credential = $this->ownedCredential( includeInactive: true );
        if ( ! $credential ) {
            return response()->json( ['status' => 404, 'message' => 'No store connected.'], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'sync_direction' => ['sometimes', Rule::in( [
                WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
                WoocommerceCredential::DIRECTION_AFFSELL_TO_WP,
                WoocommerceCredential::DIRECTION_TWO_WAY,
            ] )],
            'product_sync'   => ['sometimes', 'boolean'],
            'order_sync'     => ['sometimes', 'boolean'],
            'stock_sync'     => ['sometimes', 'boolean'],
            'auto_sync'      => ['sometimes', 'boolean'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( ['status' => 422, 'errors' => $validator->errors()], 422 );
        }

        $credential->fill( $validator->validated() );
        $credential->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Sync settings updated.',
            'data'    => $credential->fresh()->toPublicArray(),
        ] );
    }

    public function syncNow( Request $request ): JsonResponse {
        $credential = $this->ownedCredential();
        if ( ! $credential ) {
            return response()->json( ['status' => 404, 'message' => 'No connected store.'], 404 );
        }

        $type = $request->input( 'type', 'full' );
        if ( ! in_array( $type, ['full', 'products', 'orders'], true ) ) {
            return response()->json( ['status' => 422, 'message' => 'type must be full, products or orders.'], 422 );
        }

        $log = WooCommerceSyncLogger::log(
            $credential,
            'manual',
            $credential->sync_direction ?: WoocommerceCredential::DIRECTION_TWO_WAY,
            'pending',
            'Manual sync started: ' . $type
        );

        // Manual Sync Now runs inline so history leaves "pending" and products appear
        // without requiring a queue worker. Webhooks/fallback still use the job.
        try {
            $results = $this->runManualSync( $credential, $type );
            $message = $this->formatSyncMessage( $type, $results );

            $log->update( [
                'status'        => 'success',
                'message'       => $message,
                'payload_after' => $results,
            ] );
            WooCommerceSyncLogger::markCredentialSynced( $credential, true );

            return response()->json( [
                'status'  => 200,
                'message' => $message,
                'data'    => $results,
            ] );
        } catch ( \Throwable $e ) {
            Log::error( 'woo.manual_sync_failed', [
                'tenant' => tenant( 'id' ),
                'type'   => $type,
                'error'  => $e->getMessage(),
            ] );

            $log->update( [
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ] );
            WooCommerceSyncLogger::markCredentialSynced( $credential, false, $e->getMessage() );

            return response()->json( [
                'status'  => 500,
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500 );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runManualSync( WoocommerceCredential $credential, string $type ): array {
        $products = app( WooCommerceProductSyncService::class );
        $orders   = app( WooCommerceOrderSyncService::class );

        return match ( $type ) {
            'products' => ['products' => $products->syncAllFromWoo( $credential )],
            'orders'   => ['orders' => $orders->syncAllFromWoo( $credential )],
            default    => [
                'products' => $products->syncAllFromWoo( $credential ),
                'orders'   => $orders->syncAllFromWoo( $credential ),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function formatSyncMessage( string $type, array $results ): string {
        $parts = [];
        if ( isset( $results['products'] ) && is_array( $results['products'] ) ) {
            $p = $results['products'];
            $parts[] = sprintf(
                'products: imported %d, updated/skipped %d, failed %d',
                (int) ( $p['imported'] ?? 0 ),
                (int) ( $p['skipped'] ?? 0 ),
                (int) ( $p['failed'] ?? 0 )
            );
            if ( ! empty( $p['message'] ) ) {
                $parts[] = (string) $p['message'];
            }
        }
        if ( isset( $results['orders'] ) && is_array( $results['orders'] ) ) {
            $o = $results['orders'];
            $parts[] = sprintf(
                'orders: imported %d, updated %d, failed %d',
                (int) ( $o['imported'] ?? 0 ),
                (int) ( $o['updated'] ?? 0 ),
                (int) ( $o['failed'] ?? 0 )
            );
            if ( ! empty( $o['message'] ) ) {
                $parts[] = (string) $o['message'];
            }
        }

        return $parts === [] ? ( 'Manual sync finished: ' . $type ) : implode( '; ', $parts );
    }

    public function history( Request $request ): JsonResponse {
        $credential = $this->ownedCredential( includeInactive: true );
        $query      = WoocommerceSyncLog::query()->latest( 'id' );

        if ( $credential ) {
            $query->where( 'credential_id', $credential->id );
        }

        if ( $request->input( 'status' ) === 'failed' ) {
            $query->where( 'status', 'failed' );
        }

        if ( $request->filled( 'sync_type' ) ) {
            $query->where( 'sync_type', $request->input( 'sync_type' ) );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $query->paginate( 20 ),
            'meta'   => [
                'last_success_at' => optional( $credential?->last_success_at )->toIso8601String(),
                'last_synced_at'  => optional( $credential?->last_synced_at )->toIso8601String(),
            ],
        ] );
    }

    public function retry( int $logId ): JsonResponse {
        $credential = $this->ownedCredential();
        if ( ! $credential ) {
            return response()->json( ['status' => 404, 'message' => 'No connected store.'], 404 );
        }

        $log = WoocommerceSyncLog::where( 'credential_id', $credential->id )->find( $logId );
        if ( ! $log ) {
            return response()->json( ['status' => 404, 'message' => 'Sync log not found.'], 404 );
        }

        $action  = match ( $log->sync_type ) {
            'product' => 'product',
            'order'   => 'order',
            'stock'   => 'stock',
            'manual'  => 'full',
            default   => 'full',
        };
        $payload = ['manual' => true];
        if ( $log->external_id && $action === 'product' ) {
            $payload['wc_product_id'] = (int) $log->external_id;
        }
        if ( $log->external_id && $action === 'order' ) {
            $payload['wc_order_id'] = (int) $log->external_id;
        }

        $log->increment( 'retry_count' );
        $log->update( ['status' => 'pending', 'message' => 'Retry queued'] );

        WooCommerceSyncJob::dispatch(
            (string) tenant( 'id' ),
            $action,
            $payload,
            $credential->id,
            $log->id
        );

        return response()->json( [
            'status'  => 200,
            'message' => 'Retry queued.',
        ] );
    }

    private function ownedCredential( bool $includeInactive = false ): ?WoocommerceCredential {
        return WoocommerceCredential::query()
            ->where( 'vendor_id', vendorId() )
            ->when( ! $includeInactive, fn ( $q ) => $q->where( 'is_active', true ) )
            ->latest( 'id' )
            ->first()
            ?: ( $includeInactive
                ? WoocommerceCredential::query()->where( 'vendor_id', vendorId() )->latest( 'id' )->first()
                : null );
    }
}
