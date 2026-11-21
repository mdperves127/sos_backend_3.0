<?php

namespace App\Console\Commands;

use App\Jobs\WooCommerceSyncJob;
use App\Models\Tenant;
use App\Models\WoocommerceCredential;
use App\Services\CrossTenantQueryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fallback sync when webhooks are unavailable. Dispatches one job per connected merchant.
 */
class WooCommerceFallbackSyncCommand extends Command {
    protected $signature = 'woocommerce:fallback-sync {--tenant=}';

    protected $description = 'Queue WooCommerce product/order sync for merchants with auto_sync enabled';

    public function handle(): int {
        $tenants = Tenant::on( 'mysql' )
            ->where( 'type', 'merchant' )
            ->when( $this->option( 'tenant' ), fn ( $q ) => $q->where( 'id', $this->option( 'tenant' ) ) )
            ->get( ['id'] );

        $queued = 0;

        foreach ( $tenants as $tenant ) {
            try {
                $connection = CrossTenantQueryService::connectionForTenant( $tenant );
                if ( ! Schema::connection( $connection )->hasTable( 'woocommerce_credentials' ) ) {
                    continue;
                }

                $credential = DB::connection( $connection )
                    ->table( 'woocommerce_credentials' )
                    ->where( 'is_active', 1 )
                    ->where( 'auto_sync', 1 )
                    ->where( 'status', 'connected' )
                    ->whereNull( 'deleted_at' )
                    ->orderByDesc( 'id' )
                    ->first();

                if ( ! $credential ) {
                    continue;
                }

                WooCommerceSyncJob::dispatch( (string) $tenant->id, 'full', ['manual' => false], (int) $credential->id );
                $queued++;
            } catch ( \Throwable $e ) {
                $this->warn( "Tenant {$tenant->id}: " . $e->getMessage() );
            } finally {
                DB::setDefaultConnection( 'mysql' );
            }
        }

        $this->info( "Queued {$queued} WooCommerce sync job(s)." );

        return self::SUCCESS;
    }
}
