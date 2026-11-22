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
use Illuminate\Support\Facades\DB;
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

        // When QUEUE_CONNECTION=sync, this job runs inside the HTTP request.
        // Do NOT call tenancy()->end() in that case — it tears down the `tenant`
        // connection and breaks the rest of the request (and models that use it).
        $alreadyInTenant = function_exists( 'tenancy' )
            && tenancy()->initialized
            && (string) tenant( 'id' ) === (string) $this->tenantId;

        $previousDefault = config( 'database.default' );

        if ( ! $alreadyInTenant ) {
            $this->bindTenantConnection( $tenant );

            if ( function_exists( 'tenancy' ) ) {
                tenancy()->initialize( $tenant );
            }

            config( ['database.default' => 'tenant'] );
            DB::setDefaultConnection( 'tenant' );
        }

        try {
            $credential = $this->credentialId
                ? WoocommerceCredential::query()->find( $this->credentialId )
                : WoocommerceCredential::query()->where( 'is_active', true )->latest( 'id' )->first();

            if ( ! $credential || ! $credential->is_active ) {
                return;
            }

            if ( ! $credential->auto_sync && ( $this->payload['manual'] ?? false ) !== true ) {
                return;
            }

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
                WoocommerceSyncLog::query()->whereKey( $this->logId )->update( [
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

            if ( isset( $credential ) && $credential ) {
                WooCommerceSyncLogger::markCredentialSynced( $credential, false, $e->getMessage() );
            }

            throw $e;
        } finally {
            if ( ! $alreadyInTenant ) {
                if ( function_exists( 'tenancy' ) && tenancy()->initialized ) {
                    tenancy()->end();
                }
                config( ['database.default' => $previousDefault ?: 'mysql'] );
                DB::setDefaultConnection( $previousDefault ?: 'mysql' );
            }
        }
    }

    /**
     * Ensure the named `tenant` connection points at this tenant DB.
     * Many models (Product, etc.) hardcode connection = tenant.
     */
    protected function bindTenantConnection( Tenant $tenant ): void {
        $connectionName = CrossTenantQueryService::connectionForTenant( $tenant );
        $config         = config( "database.connections.{$connectionName}" );

        if ( is_array( $config ) ) {
            config( ['database.connections.tenant' => $config] );
            DB::purge( 'tenant' );
        }
    }
}
