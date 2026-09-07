<?php

namespace App\Services;

use App\Models\ProductBundle;
use App\Service\Vendor\ProductVariantService;
use RuntimeException;

/**
 * Expand product bundles into component stock movements.
 */
class BundleInventoryService
{
    /**
     * Deduct stock for every product in the bundle × sold quantity.
     */
    public static function decrementForSale( ProductBundle $bundle, int $soldQty, ?int $vendorId = null ): void
    {
        if ( $soldQty < 1 ) {
            return;
        }

        $bundle->loadMissing( 'items' );

        if ( $bundle->items->isEmpty() ) {
            throw new RuntimeException( 'Bundle has no products.' );
        }

        $vendorId = $vendorId ?? ( function_exists( 'vendorId' ) ? vendorId() : null );

        foreach ( $bundle->items as $item ) {
            $qty = max( 1, (int) $item->quantity ) * $soldQty;

            ProductVariantService::decrementStock(
                (int) $item->product_id,
                null,
                null,
                null,
                $qty,
                $vendorId ? (int) $vendorId : null
            );
        }
    }

    /**
     * Deduct on an explicit DB connection (website checkout / cross-tenant).
     */
    public static function decrementForSaleOnConnection(
        string $connectionName,
        ProductBundle $bundle,
        int $soldQty,
        ?int $vendorId = null
    ): void {
        if ( $soldQty < 1 ) {
            return;
        }

        $bundle->loadMissing( 'items' );

        if ( $bundle->items->isEmpty() ) {
            throw new RuntimeException( 'Bundle has no products.' );
        }

        foreach ( $bundle->items as $item ) {
            $qty = max( 1, (int) $item->quantity ) * $soldQty;

            ProductVariantService::decrementStockOnConnection(
                $connectionName,
                (int) $item->product_id,
                null,
                null,
                null,
                $qty,
                null,
                $vendorId
            );
        }
    }

    public static function incrementForSale( ProductBundle $bundle, int $soldQty, ?int $vendorId = null ): void
    {
        if ( $soldQty < 1 ) {
            return;
        }

        $bundle->loadMissing( 'items' );
        $vendorId = $vendorId ?? ( function_exists( 'vendorId' ) ? vendorId() : null );

        foreach ( $bundle->items as $item ) {
            $qty = max( 1, (int) $item->quantity ) * $soldQty;

            ProductVariantService::incrementStock(
                (int) $item->product_id,
                null,
                null,
                null,
                $qty,
                $vendorId ? (int) $vendorId : null
            );
        }
    }
}
