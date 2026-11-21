<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes WooCommerce store connection settings, sync toggles, and sync history.
 * Extends existing woocommerce_credentials; does not replace it.
 */
return new class extends Migration {
    public function up(): void {
        if ( Schema::hasTable( 'woocommerce_credentials' ) ) {
            Schema::table( 'woocommerce_credentials', function ( Blueprint $table ) {
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'status' ) ) {
                    $table->string( 'status', 32 )->default( 'disconnected' )->after( 'wc_url' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'connection_message' ) ) {
                    $table->string( 'connection_message' )->nullable()->after( 'status' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'sync_direction' ) ) {
                    $table->string( 'sync_direction', 32 )->default( 'two_way' )->after( 'connection_message' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'product_sync' ) ) {
                    $table->boolean( 'product_sync' )->default( true )->after( 'sync_direction' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'order_sync' ) ) {
                    $table->boolean( 'order_sync' )->default( true )->after( 'product_sync' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'stock_sync' ) ) {
                    $table->boolean( 'stock_sync' )->default( true )->after( 'order_sync' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'auto_sync' ) ) {
                    $table->boolean( 'auto_sync' )->default( true )->after( 'stock_sync' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'webhook_secret' ) ) {
                    $table->string( 'webhook_secret' )->nullable()->after( 'auto_sync' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'is_active' ) ) {
                    $table->boolean( 'is_active' )->default( true )->after( 'webhook_secret' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'last_synced_at' ) ) {
                    $table->timestamp( 'last_synced_at' )->nullable()->after( 'is_active' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'last_success_at' ) ) {
                    $table->timestamp( 'last_success_at' )->nullable()->after( 'last_synced_at' );
                }
                if ( ! Schema::hasColumn( 'woocommerce_credentials', 'last_error' ) ) {
                    $table->text( 'last_error' )->nullable()->after( 'last_success_at' );
                }
            } );
        }

        if ( ! Schema::hasTable( 'woocommerce_sync_logs' ) ) {
            Schema::create( 'woocommerce_sync_logs', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger( 'credential_id' )->nullable()->index();
                $table->string( 'sync_type', 32 ); // product|order|stock|connection|manual
                $table->string( 'direction', 32 ); // wp_to_affsell|affsell_to_wp
                $table->string( 'reference_type', 64 )->nullable();
                $table->string( 'reference_id' )->nullable()->index();
                $table->string( 'external_id' )->nullable()->index();
                $table->string( 'status', 16 )->default( 'pending' ); // pending|success|failed
                $table->text( 'message' )->nullable();
                $table->json( 'payload_before' )->nullable();
                $table->json( 'payload_after' )->nullable();
                $table->unsignedInteger( 'retry_count' )->default( 0 );
                $table->timestamps();
            } );
        }

        if ( Schema::hasTable( 'product_variants' ) && ! Schema::hasColumn( 'product_variants', 'wc_variation_id' ) ) {
            Schema::table( 'product_variants', function ( Blueprint $table ) {
                $table->string( 'wc_variation_id' )->nullable()->after( 'rate' )->index();
            } );
        }

        if ( Schema::hasTable( 'products' ) && ! Schema::hasColumn( 'products', 'wc_last_synced_at' ) ) {
            Schema::table( 'products', function ( Blueprint $table ) {
                $table->timestamp( 'wc_last_synced_at' )->nullable()->after( 'wc_product_id' );
            } );
        }

        if ( Schema::hasTable( 'orders' ) && ! Schema::hasColumn( 'orders', 'wc_last_synced_at' ) ) {
            Schema::table( 'orders', function ( Blueprint $table ) {
                $table->timestamp( 'wc_last_synced_at' )->nullable()->after( 'wc_order_no' );
            } );
        }
    }

    public function down(): void {
        if ( Schema::hasTable( 'woocommerce_sync_logs' ) ) {
            Schema::dropIfExists( 'woocommerce_sync_logs' );
        }

        if ( Schema::hasTable( 'woocommerce_credentials' ) ) {
            Schema::table( 'woocommerce_credentials', function ( Blueprint $table ) {
                foreach ( [
                    'status', 'connection_message', 'sync_direction', 'product_sync', 'order_sync',
                    'stock_sync', 'auto_sync', 'webhook_secret', 'is_active', 'last_synced_at',
                    'last_success_at', 'last_error',
                ] as $column ) {
                    if ( Schema::hasColumn( 'woocommerce_credentials', $column ) ) {
                        $table->dropColumn( $column );
                    }
                }
            } );
        }

        if ( Schema::hasTable( 'product_variants' ) && Schema::hasColumn( 'product_variants', 'wc_variation_id' ) ) {
            Schema::table( 'product_variants', function ( Blueprint $table ) {
                $table->dropColumn( 'wc_variation_id' );
            } );
        }

        if ( Schema::hasTable( 'products' ) && Schema::hasColumn( 'products', 'wc_last_synced_at' ) ) {
            Schema::table( 'products', function ( Blueprint $table ) {
                $table->dropColumn( 'wc_last_synced_at' );
            } );
        }

        if ( Schema::hasTable( 'orders' ) && Schema::hasColumn( 'orders', 'wc_last_synced_at' ) ) {
            Schema::table( 'orders', function ( Blueprint $table ) {
                $table->dropColumn( 'wc_last_synced_at' );
            } );
        }
    }
};
