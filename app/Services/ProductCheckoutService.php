<?php

namespace App\Services;

use App\Enums\Status;
use App\Models\AdvancePayment;
use App\Models\Cart;
use App\Models\CourierCredential;
use App\Models\Order;
use App\Models\OrderDeliveryToCourier;
use App\Models\PendingBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Tenant;
use App\Models\TenantCoupon;
use App\Services\CrossTenantQueryService;
use App\Services\TenantCouponService;
use Illuminate\Support\Facades\DB;

/**
 * Class ProductCheckoutService.
 */
class ProductCheckoutService {

    static function store( $cartId, $productid, $totalquantity, $userid, $datas, $paymentprocess = 'aamarpay', $merchantTenantId = null, $placingTenantId = null, $orderMedia = null, $tenantCoupon = null, $couponDiscount = 0 ) {

        try {
            // Merchant tenant = where the product/order row lives (cart->tenant_id).
            if ( !$merchantTenantId ) {
                $cartTemp = Cart::find( $cartId );
                if ( !$cartTemp || !$cartTemp->tenant_id ) {
                    return response()->json( [
                        'status'  => 400,
                        'message' => 'Cart not found or missing tenant information',
                    ] );
                }
                $merchantTenantId = $cartTemp->tenant_id;
            }

            // Placing tenant = who created the order (dropshipper tenant on affiliate checkout).
            $resolvedPlacingTenantId = $placingTenantId
                ?? ( function_exists( 'tenant' ) && tenant() ? tenant()->id : null );

            $tenant = Tenant::on('mysql')->find( $merchantTenantId );
            if ( !$tenant ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Tenant not found',
                ] );
            }

            // Get connection name using CrossTenantQueryService approach
            $connectionName = 'tenant_' . $tenant->id;
            $databaseName = $tenant->data['tenancy_db_name'] ?? ('affsellc_' . $tenant->id);

            // Configure tenant connection using CrossTenantQueryService pattern
            config([
                'database.connections.' . $connectionName => [
                    'driver' => 'mysql',
                    'host' => config('database.connections.mysql.host'),
                    'port' => config('database.connections.mysql.port'),
                    'database' => $databaseName,
                    'username' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict' => false,
                ]
            ]);
            DB::purge($connectionName);

            // Get cart from current tenant's database (carts are stored in dropshipper tenant, not product tenant)
            $cart = Cart::find( $cartId );

                if ( !$cart ) {
                    return response()->json( [
                        'status'  => 404,
                        'message' => 'Cart not found',
                    ] );
                }

            // Get product from product's tenant database (request tenant - cart->tenant_id)
            $product = CrossTenantQueryService::getSingleRecordFromTenant(
                $merchantTenantId,
                Product::class,
                function ( $query ) use ( $productid ) {
                    $query->where( 'id', $productid );
                }
            );

            if ( !$product ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product not found',
                ] );
            }

            $categoryId = $cart->category_id;
            $couponAppliedInStore = false;

            foreach ( $datas as $data ) {
                $data     = (array) $data;
                $variants = is_array( $data['variants'] ?? null ) ? $data['variants'] : [];
                $totalqty = self::resolveLineQuantity( $data, $variants, (int) $totalquantity );

                $is_unlimited = ( $cart->purchase_type == 'single' || $product->is_connect_bulk_single == 1 ) ? 0 : 1;

                // Get vendor balance from product's tenant database using CrossTenantQueryService connection
                $vendor_balance = DB::connection($connectionName)->table('users')
                    ->where('id', $product->user_id)
                    ->first();

                $afi_amount = $totalqty * $cart->amount;

                // Check if vendor balance exists and has balance property
                $vendorBalanceValue = ($vendor_balance && property_exists($vendor_balance, 'balance'))
                    ? $vendor_balance->balance
                    : 0;

                if ( $vendorBalanceValue >= $afi_amount ) {
                    $status = Status::Pending->value;
                    // Balance update is commented out, so no need to save
                    // If needed in future, use: DB::connection($connectionName)->table('users')->where('id', $product->user_id)->update(['balance' => $vendorBalanceValue - $afi_amount]);
                    // PaymentHistoryService::store( uniqid(), $afi_amount, 'My wallet', 'Affiliate commission', '-', '', $product->user_id );

                } else {
                    $status = Status::Hold->value;
                }

                $totalAmount         = convertfloat( $cart->product_price ) * convertfloat( $totalqty );
                $totaladvancepayment = $cart->advancepayment * $totalqty;

                // Get delivery charge safely, default to 0 if not provided
                $deliveryCharge = isset($data['delivery_charge']['charge'])
                    ? $data['delivery_charge']['charge']
                    : (isset($data['delivery_charge']) && is_numeric($data['delivery_charge'])
                        ? $data['delivery_charge']
                        : 0);

                $totalDue = ( $totalAmount + $deliveryCharge ) - $totaladvancepayment;

                $saleDiscount = 0.0;
                if ( ! $couponAppliedInStore && $tenantCoupon instanceof TenantCoupon && $couponDiscount > 0 ) {
                    $saleDiscount = min( (float) $couponDiscount, max( 0, $totalDue ) );
                }
                if ( $saleDiscount > 0 ) {
                    $totalDue = max( 0, $totalDue - $saleDiscount );
                }

                $customerUserId = $userid > 0 ? $userid : null;

                // Generate unique order_id using the tenant connection
                $orderId = self::generateUniqueOrderId($connectionName);

                // Create order directly in the tenant database (cart->tenant_id is the product tenant)
                $order = new Order();
                $order->setConnection($connectionName);
                $order->order_id            = $orderId;
                $order->user_id             = $customerUserId;
                $order->vendor_id           = $product->user_id;
                $order->affiliator_id       = $userid;
                $order->product_id          = $product->id;
                $order->name                = $data['name'];
                $order->phone               = $data['phone'];
                $order->email               = $data['email'];
                $order->city                = $data['city'] ?? null;
                $order->address             = $data['address'];
                $order->variants            = $variants;
                $order->afi_amount          = $afi_amount;
                $order->product_amount      = $totalAmount;
                $order->due_amount          = $totalDue;
                $order->status              = $status;
                $order->category_id         = $categoryId;
                $order->qty                 = $totalqty;
                $order->totaladvancepayment = $totaladvancepayment;
                $order->is_unlimited        = $is_unlimited;
                $order->delivery_charge     = $deliveryCharge;
                if ( $saleDiscount > 0 && $tenantCoupon instanceof TenantCoupon ) {
                    $order->sale_discount = $saleDiscount;
                    $order->coupon_code   = $tenantCoupon->code;
                }
                if ( $orderMedia !== null && $orderMedia !== '' ) {
                    $order->order_media = $orderMedia;
                }
                if ( $resolvedPlacingTenantId ) {
                    $order->tenant_id = $resolvedPlacingTenantId;
                }

                DB::connection( $connectionName )->transaction( function () use (
                    $connectionName,
                    $productid,
                    $totalqty,
                    $variants,
                    $cart,
                    $product,
                    $order,
                    $saleDiscount,
                    $tenantCoupon,
                    $customerUserId,
                    $data,
                    $userid,
                    $merchantTenantId,
                    $tenant,
                    $totaladvancepayment,
                    $afi_amount,
                    &$couponAppliedInStore
                ) {
                    $order->save();

                    self::decreaseProductStock( $connectionName, $productid, $totalqty, $variants, $cart, $product );

                    if ( $saleDiscount > 0 && $tenantCoupon instanceof TenantCoupon ) {
                        TenantCouponService::recordUsage(
                            $tenantCoupon,
                            $saleDiscount,
                            (int) $order->id,
                            $customerUserId,
                            $data['email'] ?? null
                        );
                        $couponAppliedInStore = true;
                    }

                    $courierCredentials = CrossTenantQueryService::queryTenant(
                        $tenant,
                        CourierCredential::class,
                        function ( $query ) use ( $product ) {
                            $query->where( 'vendor_id', $product->vendor_id ?? $product->user_id )
                                ->where( 'status', 'active' )
                                ->where( 'default', 'yes' );
                        }
                    );

                    if ( $courierCredentials->isNotEmpty() ) {
                        $defaultCourier = $courierCredentials->first();
                        $isPathao       = CrossTenantQueryService::queryTenant(
                            $tenant,
                            CourierCredential::class,
                            function ( $query ) use ( $product ) {
                                $query->where( 'vendor_id', $product->vendor_id ?? $product->user_id )
                                    ->where( 'status', 'active' )
                                    ->where( 'default', 'yes' )
                                    ->where( 'courier_name', 'pathao' );
                            }
                        )->isNotEmpty();
                        $isRedx = CrossTenantQueryService::queryTenant(
                            $tenant,
                            CourierCredential::class,
                            function ( $query ) use ( $product ) {
                                $query->where( 'vendor_id', $product->vendor_id ?? $product->user_id )
                                    ->where( 'status', 'active' )
                                    ->where( 'default', 'yes' )
                                    ->where( 'courier_name', 'redx' );
                            }
                        )->isNotEmpty();

                        CrossTenantQueryService::saveToTenant(
                            $merchantTenantId,
                            OrderDeliveryToCourier::class,
                            function ( $model ) use ( $order, $product, $userid, $data, $isPathao, $isRedx, $defaultCourier, $totalqty ) {
                                $model->order_id            = $order->id;
                                $model->vendor_id           = $product->user_id;
                                $model->affiliator_id       = $userid;
                                $model->merchant_order_id   = $order->order_id;
                                $model->recipient_name      = $data['name'];
                                $model->recipient_phone     = $data['phone'];
                                $model->recipient_address   = $data['address'];
                                $model->courier_id          = $data['courier_id'] ?? $defaultCourier?->id;
                                $model->item_weight         = $data['item_weight'] ?? null;
                                $model->recipient_city      = $isPathao ? ( $data['city_id'] ?? null ) : null;
                                $model->recipient_zone      = $isPathao ? ( $data['zone_id'] ?? null ) : null;
                                $model->recipient_area      = $isPathao ? ( $data['area_id'] ?? null ) : ( $isRedx ? ( $data['area_id'] ?? null ) : null );
                                $model->area_name           = $isRedx ? ( $data['area_name'] ?? null ) : null;
                                $model->delivery_type       = 48;
                                $model->item_type           = $data['item_type'] ?? null;
                                $model->special_instruction = $data['special_instruction'] ?? null;
                                $model->item_quantity       = $data['item_quantity'] ?? $totalqty;
                                $model->amount_to_collect   = $order->due_amount;
                                $model->item_description    = $data['item_description'] ?? null;
                            }
                        );
                    }

                    if ( $totaladvancepayment > 0 ) {
                        CrossTenantQueryService::saveToTenant(
                            $merchantTenantId,
                            AdvancePayment::class,
                            function ( $model ) use ( $product, $userid, $totalqty, $totaladvancepayment, $order ) {
                                $model->vendor_id    = $product->user_id;
                                $model->affiliate_id = $userid;
                                $model->product_id   = $product->id;
                                $model->qty          = $totalqty;
                                $model->amount       = $totaladvancepayment;
                                $model->order_id     = $order->id;
                            }
                        );
                    }

                    CrossTenantQueryService::saveToTenant(
                        $merchantTenantId,
                        PendingBalance::class,
                        function ( $model ) use ( $userid, $product, $order, $totalqty, $afi_amount ) {
                            $model->affiliator_id = $userid;
                            $model->product_id    = $product->id;
                            $model->order_id      = $order->id;
                            $model->qty           = $totalqty;
                            $model->amount        = $afi_amount;
                            $model->status        = Status::Pending->value;
                        }
                    );
                } );
            }

            $paymentHistoryUserId = $userid > 0 ? $userid : 1;
            $paymentHistoryContext = [];

            if ( $resolvedPlacingTenantId ) {
                $paymentHistoryContext = [
                    'entity_type' => 'tenant',
                    'tenant_id'   => $resolvedPlacingTenantId,
                    'user_id'     => $paymentHistoryUserId,
                ];
            } elseif ( function_exists( 'tenant' ) && tenant() ) {
                $paymentHistoryContext = [
                    'entity_type' => 'tenant',
                    'tenant_id'   => tenant()->id,
                    'user_id'     => $paymentHistoryUserId,
                ];
            }

            PaymentHistoryService::store(
                uniqid(),
                ( $cart->advancepayment * $totalquantity ),
                $paymentprocess,
                'Advance payment',
                '-',
                '',
                $paymentHistoryUserId,
                $paymentHistoryContext
            );

            // Delete cart from current tenant's database (carts are stored in dropshipper tenant, not product tenant)
            $cartToDelete = Cart::find( $cartId );

            if ( $cartToDelete ) {
                $cartToDelete->delete();
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Checkout successfully!',
            ] );
        } catch ( \Exception $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => $e->getMessage(),
            ] );
        }
    }

    /**
     * Generate unique order ID for a specific tenant database
     *
     * @param string $connectionName
     * @return string
     */
    private static function generateUniqueOrderId($connectionName)
    {
        do {
            $text = 'OR';
            $number = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $orderId = $text . $number;

            // Check if order_id exists in the tenant database
            $exists = DB::connection($connectionName)
                ->table('orders')
                ->where('order_id', $orderId)
                ->exists();
        } while ($exists);

        return $orderId;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $variants
     */
    private static function resolveLineQuantity( array $data, array $variants, int $totalquantity ): int {
        $totalqty = (int) collect( $variants )->sum( 'qty' );

        if ( $totalqty < 1 ) {
            $totalqty = (int) ( $data['qty'] ?? $data['product_qty'] ?? $data['quantity'] ?? $totalquantity );
        }

        if ( $totalqty < 1 ) {
            $totalqty = $totalquantity;
        }

        return max( 0, $totalqty );
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private static function resolveVariantQuantity( array $variant, int $fallbackQty ): int {
        $qty = (int) ( $variant['qty'] ?? $variant['quantity'] ?? 0 );

        return $qty > 0 ? $qty : max( 1, $fallbackQty );
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @param  object  $product  Product model or stdClass from cross-tenant query
     */
    private static function decreaseProductStock(
        string $connectionName,
        int $productId,
        int $totalqty,
        array $variants,
        Cart $cart,
        object $product
    ): void {
        if ( $cart->purchase_type != 'single' && (int) ( $product->is_connect_bulk_single ?? 0 ) !== 1 ) {
            return;
        }

        $currentProduct = DB::connection( $connectionName )->table( 'products' )
            ->where( 'id', $productId )
            ->first();

        if ( $currentProduct ) {
            DB::connection( $connectionName )->table( 'products' )
                ->where( 'id', $productId )
                ->update( ['qty' => max( 0, (int) $currentProduct->qty - $totalqty )] );
        }

        foreach ( $variants as $variant ) {
            $variant = (array) $variant;

            if ( empty( $variant['variant_id'] ) ) {
                continue;
            }

            $variantQty = self::resolveVariantQuantity( $variant, $totalqty );

            $currentVariant = DB::connection( $connectionName )->table( 'product_variants' )
                ->where( 'id', $variant['variant_id'] )
                ->first();

            if ( $currentVariant ) {
                DB::connection( $connectionName )->table( 'product_variants' )
                    ->where( 'id', $variant['variant_id'] )
                    ->update( ['qty' => max( 0, (int) $currentVariant->qty - $variantQty )] );
            }
        }

        $databaseValue = DB::connection( $connectionName )->table( 'products' )
            ->where( 'id', $productId )
            ->first();

        if ( ! $databaseValue || $databaseValue->variants == '' ) {
            return;
        }

        $variantsArray = is_string( $databaseValue->variants )
            ? json_decode( $databaseValue->variants, true )
            : $databaseValue->variants;

        if ( ! is_array( $variantsArray ) ) {
            return;
        }

        $result = [];

        foreach ( $variantsArray as $dbItem ) {
            $dbItem = (array) $dbItem;

            foreach ( $variants as $userItem ) {
                $userItem = (array) $userItem;

                if (
                    ! empty( $userItem['variant_id'] )
                    && isset( $dbItem['id'] )
                    && (int) $dbItem['id'] === (int) $userItem['variant_id']
                ) {
                    $deductQty     = self::resolveVariantQuantity( $userItem, $totalqty );
                    $dbItem['qty'] = max( 0, (int) ( $dbItem['qty'] ?? 0 ) - $deductQty );
                    break;
                }
            }

            $result[] = $dbItem;
        }

        DB::connection( $connectionName )->table( 'products' )
            ->where( 'id', $productId )
            ->update( ['variants' => json_encode( $result )] );
    }
}
