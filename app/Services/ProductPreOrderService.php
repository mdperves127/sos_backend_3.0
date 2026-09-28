<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPreOrder;
use App\Models\User;
use App\Notifications\PreOrderCustomerNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductPreOrderService
{
    public static function isPreOrderProduct( object $product ): bool
    {
        return (string) ( $product->pre_order ?? '0' ) === '1';
    }

    public static function activeSettingsForProduct( int $productId ): ?ProductPreOrder
    {
        return ProductPreOrder::where( 'product_id', $productId )
            ->where( 'status', 'active' )
            ->first();
    }

    /**
     * Upsert pre-order config and sync products.pre_order + advance_payment for cart compatibility.
     *
     * @param  array<string, mixed>  $data
     */
    public static function saveSettings( Product $product, array $data ): ProductPreOrder
    {
        $enabled = filter_var( $data['enabled'] ?? ( $data['pre_order'] ?? false ), FILTER_VALIDATE_BOOLEAN )
            || (string) ( $data['pre_order'] ?? '' ) === '1';

        $product->pre_order = $enabled ? '1' : '0';

        $settings = ProductPreOrder::firstOrNew( ['product_id' => $product->id] );

        if ( array_key_exists( 'expected_delivery_date', $data ) ) {
            $settings->expected_delivery_date = $data['expected_delivery_date'];
        }
        if ( array_key_exists( 'quantity_limit', $data ) ) {
            $settings->quantity_limit = max( 0, (int) $data['quantity_limit'] );
        }
        if ( array_key_exists( 'advance_amount', $data ) ) {
            $settings->advance_amount = round( (float) $data['advance_amount'], 2 );
            $product->advance_payment = $settings->advance_amount;
            if ( $settings->advance_amount > 0 && empty( $product->single_advance_payment_type ) ) {
                $product->single_advance_payment_type = 'flat';
            }
        }
        if ( array_key_exists( 'payment_options', $data ) ) {
            $settings->payment_options = $data['payment_options'];
        }
        if ( array_key_exists( 'status', $data ) && $data['status'] !== null && $data['status'] !== '' ) {
            $settings->status = $data['status'];
        } elseif ( $enabled ) {
            $settings->status = $settings->status ?: 'active';
        } else {
            // Turning OFF must not wipe history — close config only.
            $settings->status = 'closed';
        }

        if ( ! $settings->exists ) {
            $settings->quantity_ordered = 0;
            $settings->payment_options  = $settings->payment_options ?: 'both';
            $settings->status           = $enabled ? 'active' : 'closed';
        }

        if ( $enabled && (int) $settings->quantity_limit <= 0 ) {
            throw ValidationException::withMessages( [
                'quantity_limit' => ['Pre-order quantity limit is required when pre-order is enabled.'],
            ] );
        }

        if ( $enabled && empty( $settings->expected_delivery_date ) ) {
            throw ValidationException::withMessages( [
                'expected_delivery_date' => ['Expected delivery date is required when pre-order is enabled.'],
            ] );
        }

        $settings->product_id = $product->id;
        $settings->save();
        $product->save();

        return $settings->fresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function publicPayload( ?ProductPreOrder $settings, object $product ): ?array
    {
        if ( ! self::isPreOrderProduct( $product ) || ! $settings ) {
            return null;
        }

        $unitPrice = (float) ( $product->discount_price ?? $product->selling_price ?? 0 );

        return [
            'enabled'                => $settings->status === 'active',
            'status'                 => $settings->status,
            'expected_delivery_date' => optional( $settings->expected_delivery_date )->format( 'Y-m-d' ),
            'quantity_limit'         => (int) $settings->quantity_limit,
            'quantity_ordered'       => (int) $settings->quantity_ordered,
            'remaining_quantity'     => (int) $settings->remaining_quantity,
            'advance_amount'         => (float) $settings->advance_amount,
            'payment_options'        => $settings->payment_options,
            'unit_price'             => $unitPrice,
            'is_available'           => $settings->is_available,
        ];
    }

    /**
     * Resolve advance/full payment for a cart line.
     *
     * @return array{payment_type:string,advance_payment:float}
     */
    public static function resolvePayment( object $product, ProductPreOrder $settings, string $choice, float $unitPrice ): array
    {
        $options = $settings->payment_options ?: 'both';
        $choice  = in_array( $choice, ['advance', 'full'], true ) ? $choice : 'advance';

        if ( $options === 'advance' ) {
            $choice = 'advance';
        } elseif ( $options === 'full' ) {
            $choice = 'full';
        }

        if ( $choice === 'full' ) {
            return [
                'payment_type'    => 'full',
                'advance_payment' => round( $unitPrice, 2 ),
            ];
        }

        $advance = (float) $settings->advance_amount;
        if ( $advance <= 0 ) {
            $advance = (float) ( $product->advance_payment ?? 0 );
            if ( ( $product->single_advance_payment_type ?? null ) === 'percent' ) {
                $advance = ( $unitPrice / 100 ) * (float) ( $product->advance_payment ?? 0 );
            }
        }

        if ( $advance <= 0 ) {
            throw ValidationException::withMessages( [
                'pre_order_payment' => ['Advance payment is not configured for this pre-order product.'],
            ] );
        }

        if ( $advance > $unitPrice ) {
            $advance = $unitPrice;
        }

        return [
            'payment_type'    => 'advance',
            'advance_payment' => round( $advance, 2 ),
        ];
    }

    /**
     * Atomically reserve pre-order quantity. Throws if limit exceeded or closed.
     */
    public static function reserveQuantity( string $connectionName, int $productId, int $qty ): ProductPreOrder
    {
        return DB::connection( $connectionName )->transaction( function () use ( $connectionName, $productId, $qty ) {
            $settings = ProductPreOrder::on( $connectionName )
                ->where( 'product_id', $productId )
                ->lockForUpdate()
                ->first();

            if ( ! $settings || $settings->status !== 'active' ) {
                throw ValidationException::withMessages( [
                    'pre_order' => ['Pre-order is not available for this product.'],
                ] );
            }

            if ( (int) $settings->quantity_ordered + $qty > (int) $settings->quantity_limit ) {
                $remaining = max( 0, (int) $settings->quantity_limit - (int) $settings->quantity_ordered );
                throw ValidationException::withMessages( [
                    'pre_order' => ["Pre-order limit reached. Remaining slots: {$remaining}."],
                ] );
            }

            $settings->quantity_ordered = (int) $settings->quantity_ordered + $qty;
            $settings->save();

            return $settings;
        } );
    }

    public static function releaseQuantity( string $connectionName, int $productId, int $qty ): void
    {
        if ( $qty <= 0 ) {
            return;
        }

        DB::connection( $connectionName )->transaction( function () use ( $connectionName, $productId, $qty ) {
            $settings = ProductPreOrder::on( $connectionName )
                ->where( 'product_id', $productId )
                ->lockForUpdate()
                ->first();

            if ( ! $settings ) {
                return;
            }

            $settings->quantity_ordered = max( 0, (int) $settings->quantity_ordered - $qty );
            $settings->save();
        } );
    }

    public static function assertSlotsAvailable( object $product, int $qty, ?string $connectionName = null ): void
    {
        if ( ! self::isPreOrderProduct( $product ) ) {
            return;
        }

        $query = $connectionName
            ? ProductPreOrder::on( $connectionName )
            : ProductPreOrder::query();

        $settings = $query->where( 'product_id', (int) $product->id )
            ->where( 'status', 'active' )
            ->first();

        if ( ! $settings ) {
            throw ValidationException::withMessages( [
                'pre_order' => ['Pre-order is not available for this product.'],
            ] );
        }

        if ( $qty > $settings->remaining_quantity ) {
            throw ValidationException::withMessages( [
                'pre_order' => [
                    'Only ' . $settings->remaining_quantity . ' pre-order slot(s) remaining.',
                ],
            ] );
        }
    }

    public static function notifyCustomer( ?int $userId, Order $order, string $event ): void
    {
        if ( ! $userId || $userId <= 0 ) {
            return;
        }

        $user = User::find( $userId );
        if ( ! $user ) {
            return;
        }

        $user->notify( new PreOrderCustomerNotification( $order, $event ) );
    }

    /**
     * Map existing order status → customer-facing pre-order lifecycle label.
     */
    public static function lifecycleFromOrderStatus( string $status ): string
    {
        return match ( $status ) {
            'cancel', 'rejected' => 'cancelled',
            'processing', 'progress' => 'processing',
            'ready' => 'ready',
            'delivered', 'completed', 'success' => 'completed',
            'courier' => 'processing',
            default => 'confirmed',
        };
    }
}
