<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WoocommerceCredential;
use App\Models\WoocommerceSyncLog;
use App\Services\CrossTenantQueryService;
use App\Services\WooCommerce\WooCommerceOrderSyncService;
use App\Services\WooCommerce\WooCommerceProductSyncService;
use App\Services\WooCommerce\WooCommerceStockSyncService;
use App\Services\WooCommerce\WooCommerceSyncLogger;
use App\Services\WooCommerceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WooCommerceSyncJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  string  $tenantId
     * @param  string  $action  products|orders|product|order|stock|full
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public string $action,
        public array $payload = [],
        public ?int $credentialId = null,
        public ?int $logId = null
    ) {
    }

    public function handle(
        WooCommerceService $woo,
        WooCommerceProductSyncService $products,
        WooCommerceOrderSyncService $orders,
        WooCommerceStockSyncService $stock
    ): void {
        $tenant = Tenant::on( 'mysql' )->find( $this->tenantId );
        if ( ! $tenant ) {
            return;
        }

        $connection = CrossTenantQueryService::connectionForTenant( $tenant );
        config( ['database.default' => $connection] );

        // Initialize tenancy context for helpers that call tenant().
        if ( function_exists( 'tenancy' ) ) {
            tenancy()->initialize( $tenant );
        }

        $credential = $this->credentialId
            ? WoocommerceCredential::on( $connection )->find( $this->credentialId )
            : WoocommerceCredential::on( $connection )->where( 'is_active', true )->latest( 'id' )->first();

        if ( ! $credential || ! $credential->is_active ) {
            return;
        }

        if ( ! $credential->auto_sync && ( $this->payload['manual'] ?? false ) !== true ) {
            return;
        }

        try {
            match ( $this->action ) {
                'products' => $products->syncAllFromWoo( $credential ),
                'orders'   => $orders->syncAllFromWoo( $credential ),
                'product'  => $products->importFromWoo(
                    $credential,
                    $this->payload['wc_product'] ?? null,
                    isset( $this->payload['wc_product_id'] ) ? (int) $this->payload['wc_product_id'] : null
                ),
                'order'    => $orders->importFromWoo(
                    $credential,
                    $this->payload['wc_order'] ?? null,
                    isset( $this->payload['wc_order_id'] ) ? (int) $this->payload['wc_order_id'] : null
                ),
                'stock'    => isset( $this->payload['wc_product'] )
                    ? $stock->applyFromWoo( $credential, $this->payload['wc_product'] )
                    : null,
                'full'     => [
                    $products->syncAllFromWoo( $credential ),
                    $orders->syncAllFromWoo( $credential ),
                ],
                default    => null,
            };

            if ( $this->logId ) {
                WoocommerceSyncLog::on( $connection )->whereKey( $this->logId )->update( [
                    'status'      => 'success',
                    'retry_count' => $this->attempts(),
                    'message'     => 'Retry succeeded',
                ] );
            }
        } catch ( \Throwable $e ) {
            Log::error( 'woo.sync_job_failed', [
                'tenant' => $this->tenantId,
                'action' => $this->action,
                'error'  => $e->getMessage(),
            ] );
            WooCommerceSyncLogger::markCredentialSynced( $credential, false, $e->getMessage() );
            throw $e;
        } finally {
            if ( function_exists( 'tenancy' ) && tenancy()->initialized ) {
                tenancy()->end();
            }
        }
    }
}
