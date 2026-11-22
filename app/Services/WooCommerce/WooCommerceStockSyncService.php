<?php

namespace App\Services\WooCommerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WoocommerceCredential;
use App\Services\WooCommerceService;
use Illuminate\Support\Facades\Schema;

class WooCommerceStockSyncService {
    public function __construct( protected WooCommerceService $woo ) {
    }

    /**
     * Apply WooCommerce stock onto AffSell when WC data is newer (or local has no sync stamp).
     */
    public function applyFromWoo( WoocommerceCredential $credential, array $wcProduct ): ?Product {
        if ( ! $credential->canSyncStock( WoocommerceCredential::DIRECTION_WP_TO_AFFSELL ) ) {
            return null;
        }

        $wcId = $wcProduct['id'] ?? null;
        if ( ! $wcId ) {
            return null;
        }

        if ( WooCommerceLoopGuard::isMarked( 'stock', $wcId ) ) {
            return Product::where( 'wc_product_id', 'WC-' . $wcId )->first();
        }

        $product = Product::where( 'wc_product_id', 'WC-' . $wcId )->first();
        if ( ! $product ) {
            return null;
        }

        if ( ! empty( $wcProduct['manage_stock'] ) ) {
            $incomingQty = (int) ( $wcProduct['stock_quantity'] ?? 0 );
            // Conflict rule: only overwrite if local qty differs and we are not mid AffSell→WC push.
            if ( ! WooCommerceLoopGuard::isMarked( 'stock_local', $product->id ) && (int) $product->qty !== $incomingQty ) {
                $before = ['qty' => $product->qty];
                $product->qty = $incomingQty;
                if ( Schema->hasColumn( 'products', 'wc_last_synced_at' ) ) {
                    $product->wc_last_synced_at = now();
                }
                $product->save();

                WooCommerceLoopGuard::mark( 'stock_local', $product->id );
                WooCommerceSyncLogger::log(
                    $credential,
                    'stock',
                    WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
                    'success',
                    'Stock updated from WooCommerce',
                    'product',
                    (string) $product->id,
                    (string) $wcId,
                    $before,
                    ['qty' => $incomingQty]
                );
            }
        }

        return $product;
    }

    public function pushToWoo( WoocommerceCredential $credential, Product $product ): bool {
        if ( ! $credential->canSyncStock( WoocommerceCredential::DIRECTION_AFFSELL_TO_WP ) ) {
            return false;
        }

        $wcId = $this->parseWcId( $product->wc_product_id );
        if ( ! $wcId ) {
            return false;
        }

        if ( WooCommerceLoopGuard::isMarked( 'stock', $wcId ) ) {
            return false;
        }

        try {
            $this->woo->forCredential( $credential )->put( 'products/' . $wcId, [
                'manage_stock'   => true,
                'stock_quantity' => (int) $product->qty,
                'stock_status'   => ( (int) $product->qty ) > 0 ? 'instock' : 'outofstock',
            ] );

            WooCommerceLoopGuard::mark( 'stock', $wcId );

            if ( Schema->hasColumn( 'product_variants', 'wc_variation_id' ) ) {
                $variants = ProductVariant::where( 'product_id', $product->id )
                    ->whereNotNull( 'wc_variation_id' )
                    ->get();
                foreach ( $variants as $variant ) {
                    $this->woo->forCredential( $credential )->put(
                        'products/' . $wcId . '/variations/' . $variant->wc_variation_id,
                        [
                            'manage_stock'   => true,
                            'stock_quantity' => (int) $variant->qty,
                        ]
                    );
                }
            }

            WooCommerceSyncLogger::log(
                $credential,
                'stock',
                WoocommerceCredential::DIRECTION_AFFSELL_TO_WP,
                'success',
                'Stock pushed to WooCommerce',
                'product',
                (string) $product->id,
                (string) $wcId,
                null,
                ['qty' => $product->qty]
            );

            return true;
        } catch ( \Throwable $e ) {
            WooCommerceSyncLogger::log(
                $credential,
                'stock',
                WoocommerceCredential::DIRECTION_AFFSELL_TO_WP,
                'failed',
                $e->getMessage(),
                'product',
                (string) $product->id,
                (string) $wcId
            );

            return false;
        }
    }

    protected function parseWcId( ?string $wcProductId ): ?int {
        if ( ! $wcProductId ) {
            return null;
        }
        if ( str_starts_with( $wcProductId, 'WC-' ) ) {
            return (int) substr( $wcProductId, 3 );
        }

        return is_numeric( $wcProductId ) ? (int) $wcProductId : null;
    }
}
