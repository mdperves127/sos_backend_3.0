<?php

namespace App\Services\WooCommerce;

use App\Models\Order;
use App\Models\OrderDetails;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WoocommerceCredential;
use App\Services\WooCommerceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WooCommerceOrderSyncService {
    /** AffSell status => WooCommerce status */
    public const AFFSELL_TO_WC = [
        'pending'    => 'pending',
        'hold'       => 'on-hold',
        'received'   => 'processing',
        'processing' => 'processing',
        'ready'      => 'processing',
        'progress'   => 'processing',
        'courier'    => 'processing',
        'delivered'  => 'completed',
        'cancel'     => 'cancelled',
        'return'     => 'refunded',
    ];

    /** WooCommerce status => AffSell status */
    public const WC_TO_AFFSELL = [
        'pending'    => 'pending',
        'on-hold'    => 'hold',
        'processing' => 'processing',
        'completed'  => 'delivered',
        'cancelled'  => 'cancel',
        'refunded'   => 'return',
        'failed'     => 'return',
    ];

    public function __construct( protected WooCommerceService $woo ) {
    }

    public function importFromWoo( WoocommerceCredential $credential, ?array $wcOrder = null, ?int $wcOrderId = null ): ?Order {
        if ( ! $credential->canSyncOrders( WoocommerceCredential::DIRECTION_WP_TO_AFFSELL ) ) {
            return null;
        }

        $client = $this->woo->forCredential( $credential );
        if ( $wcOrder === null ) {
            if ( ! $wcOrderId ) {
                return null;
            }
            $wcOrder = $client->get( 'orders/' . $wcOrderId )['data'] ?? null;
        }

        if ( ! is_array( $wcOrder ) || empty( $wcOrder['id'] ) ) {
            return null;
        }

        $orderKey = 'WC-' . ( $wcOrder['number'] ?? $wcOrder['id'] );

        if ( WooCommerceLoopGuard::isMarked( 'order', $wcOrder['id'] ) ) {
            return Order::where( 'wc_order_no', $orderKey )->first();
        }

        try {
            $existing = Order::where( 'wc_order_no', $orderKey )->first();
            if ( $existing ) {
                return $this->updateExistingFromWoo( $existing, $wcOrder, $credential );
            }

            $order = $this->createFromWoo( $wcOrder, $credential, $orderKey );

            WooCommerceSyncLogger::log(
                $credential,
                'order',
                WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
                'success',
                'Order imported from WooCommerce',
                'order',
                (string) $order->id,
                (string) $wcOrder['id']
            );
            WooCommerceSyncLogger::markCredentialSynced( $credential, true );

            return $order;
        } catch ( \Throwable $e ) {
            Log::error( 'woo.order_import_failed', ['error' => $e->getMessage(), 'wc_id' => $wcOrder['id'] ?? null] );
            WooCommerceSyncLogger::log(
                $credential,
                'order',
                WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
                'failed',
                $e->getMessage(),
                'order',
                null,
                (string) ( $wcOrder['id'] ?? '' )
            );
            WooCommerceSyncLogger::markCredentialSynced( $credential, false, $e->getMessage() );
            throw $e;
        }
    }

    public function syncAllFromWoo( WoocommerceCredential $credential ): array {
        if ( ! $credential->canSyncOrders( WoocommerceCredential::DIRECTION_WP_TO_AFFSELL ) ) {
            return ['imported' => 0, 'updated' => 0, 'failed' => 0, 'message' => 'Order sync disabled'];
        }

        $orders = $this->woo->forCredential( $credential )->paginateAll( 'orders' );
        $stats  = ['imported' => 0, 'updated' => 0, 'failed' => 0];

        foreach ( $orders as $wcOrder ) {
            try {
                $key      = 'WC-' . ( $wcOrder['number'] ?? $wcOrder['id'] );
                $existed  = Order::where( 'wc_order_no', $key )->exists();
                $this->importFromWoo( $credential, $wcOrder );
                $existed ? $stats['updated']++ : $stats['imported']++;
            } catch ( \Throwable $e ) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    public function pushStatusToWoo( WoocommerceCredential $credential, Order $order, ?string $affsellStatus = null ): bool {
        if ( ! $credential->canSyncOrders( WoocommerceCredential::DIRECTION_AFFSELL_TO_WP ) ) {
            return false;
        }

        if ( empty( $order->wc_order_no ) ) {
            return false;
        }

        $wcOrderId = $this->parseWcOrderId( $order->wc_order_no );
        if ( ! $wcOrderId ) {
            return false;
        }

        if ( WooCommerceLoopGuard::isMarked( 'order', $wcOrderId ) ) {
            return false;
        }

        $status   = $affsellStatus ?: (string) $order->status;
        $wcStatus = self::AFFSELL_TO_WC[$status] ?? null;
        if ( ! $wcStatus ) {
            return false;
        }

        try {
            $this->woo->forCredential( $credential )->put( 'orders/' . $wcOrderId, [
                'status' => $wcStatus,
            ] );

            WooCommerceLoopGuard::mark( 'order_local', $order->id );
            if ( Schema->hasColumn( 'orders', 'wc_last_synced_at' ) ) {
                $order->wc_last_synced_at = now();
                $order->save();
            }

            WooCommerceSyncLogger::log(
                $credential,
                'order',
                WoocommerceCredential::DIRECTION_AFFSELL_TO_WP,
                'success',
                'Order status pushed to WooCommerce: ' . $wcStatus,
                'order',
                (string) $order->id,
                (string) $wcOrderId
            );

            return true;
        } catch ( \Throwable $e ) {
            WooCommerceSyncLogger::log(
                $credential,
                'order',
                WoocommerceCredential::DIRECTION_AFFSELL_TO_WP,
                'failed',
                $e->getMessage(),
                'order',
                (string) $order->id,
                (string) $wcOrderId
            );

            return false;
        }
    }

    protected function createFromWoo( array $wcOrder, WoocommerceCredential $credential, string $orderKey ): Order {
        $vendorId = function_exists( 'vendorId' ) ? vendorId() : $credential->vendor_id;
        $billing  = $wcOrder['billing'] ?? [];
        $shipping = $wcOrder['shipping'] ?? [];
        $status   = self::WC_TO_AFFSELL[$wcOrder['status'] ?? 'pending'] ?? 'pending';

        $order                   = new Order();
        $order->vendor_id        = $vendorId;
        $order->name             = trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ) ?: 'Customer';
        $order->email            = $billing['email'] ?? null;
        $order->phone            = $this->normalizePhone( $billing['phone'] ?? '' );
        $order->address          = trim( ( $shipping['address_1'] ?? $billing['address_1'] ?? '' ) . ' ' . ( $shipping['address_2'] ?? $billing['address_2'] ?? '' ) );
        $order->city             = $shipping['city'] ?? $billing['city'] ?? null;
        $order->wc_order_no      = $orderKey;
        $order->product_amount   = (float) ( $wcOrder['total'] ?? 0 );
        $order->due_amount       = ( ( $wcOrder['payment_method'] ?? '' ) === 'cod' ) ? (float) ( $wcOrder['total'] ?? 0 ) : 0;
        $order->order_media      = 'Woocommerce';
        $order->status           = $status;
        $order->last_status      = $status;
        $order->category_id      = 0;
        $order->custom_order     = 1;
        $order->qty = function_exists( 'getWcItemTotalQty' ) ? getWcItemTotalQty( $wcOrder ) : $this->lineQty( $wcOrder );
        if ( Schema->hasColumn( 'orders', 'payment_method' ) ) {
            $order->payment_method = $wcOrder['payment_method_title'] ?? $wcOrder['payment_method'] ?? null;
        }
        if ( Schema->hasColumn( 'orders', 'wc_last_synced_at' ) ) {
            $order->wc_last_synced_at = now();
        }
        $order->save();

        $this->syncLineItems( $order, $wcOrder['line_items'] ?? [] );
        WooCommerceLoopGuard::mark( 'order_local', $order->id );

        return $order;
    }

    protected function updateExistingFromWoo( Order $order, array $wcOrder, WoocommerceCredential $credential ): Order {
        if ( WooCommerceLoopGuard::isMarked( 'order_local', $order->id ) ) {
            return $order;
        }

        $status = self::WC_TO_AFFSELL[$wcOrder['status'] ?? ''] ?? null;
        if ( $status && $status !== $order->status ) {
            // Do not override final AffSell states from stale WC updates unless WC is also final.
            if ( ! in_array( $order->status, ['delivered', 'cancel', 'return'], true )
                || in_array( $status, ['delivered', 'cancel', 'return'], true ) ) {
                $order->status      = $status;
                $order->last_status = $status;
            }
        }

        if ( Schema->hasColumn( 'orders', 'wc_last_synced_at' ) ) {
            $order->wc_last_synced_at = now();
        }
        $order->save();

        WooCommerceLoopGuard::mark( 'order_local', $order->id );

        WooCommerceSyncLogger::log(
            $credential,
            'order',
            WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
            'success',
            'Order updated from WooCommerce',
            'order',
            (string) $order->id,
            (string) ( $wcOrder['id'] ?? '' )
        );

        return $order;
    }

    protected function syncLineItems( Order $order, array $lineItems ): void {
        foreach ( $lineItems as $item ) {
            $wcProductId = $item['product_id'] ?? null;
            $localProduct = $wcProductId
                ? Product::where( 'wc_product_id', 'WC-' . $wcProductId )->first()
                : null;

            // Fallback: match by SKU
            if ( ! $localProduct && ! empty( $item['sku'] ) ) {
                $localProduct = Product::where( 'sku', $item['sku'] )->first();
            }

            $variantId = null;
            if ( ! empty( $item['variation_id'] ) && Schema->hasColumn( 'product_variants', 'wc_variation_id' ) ) {
                $variantId = ProductVariant::where( 'wc_variation_id', (string) $item['variation_id'] )->value( 'id' );
            }

            $detail             = new OrderDetails();
            $detail->order_id   = $order->id;
            $detail->product_id = $localProduct?->id ?? 0;
            $detail->sub_qty    = (int) ( $item['quantity'] ?? 0 );
            $detail->rate       = (float) ( $item['price'] ?? 0 );
            $detail->sub_total  = (float) ( $item['subtotal'] ?? 0 );
            if ( Schema->hasColumn( 'order_details', 'variant_id' ) ) {
                $detail->variant_id = $variantId;
            }
            $detail->save();
        }
    }

    protected function parseWcOrderId( string $wcOrderNo ): ?int {
        if ( str_starts_with( $wcOrderNo, 'WC-' ) ) {
            return (int) substr( $wcOrderNo, 3 );
        }

        return is_numeric( $wcOrderNo ) ? (int) $wcOrderNo : null;
    }

    protected function normalizePhone( string $phone ): string {
        $digits = preg_replace( '/\D+/', '', $phone ) ?? '';

        return $digits !== '' ? $digits : '00000000';
    }

    protected function lineQty( array $wcOrder ): int {
        $qty = 0;
        foreach ( $wcOrder['line_items'] ?? [] as $item ) {
            $qty += (int) ( $item['quantity'] ?? 0 );
        }

        return $qty;
    }
}
