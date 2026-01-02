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
use App\Services\CrossTenantQueryService;
use Illuminate\Support\Facades\DB;

/**
 * Class ProductCheckoutService.
 */
class ProductCheckoutService {

    static function store( $cartId, $productid, $totalquantity, $userid, $datas, $paymentprocess = 'aamarpay', $tenantId = null ) {

        try {
            // Get tenant_id from parameter or from cart
            if ( !$tenantId ) {
                $cartTemp = Cart::find( $cartId );
                if ( !$cartTemp || !$cartTemp->tenant_id ) {
                    return response()->json( [
                        'status'  => 400,
                        'message' => 'Cart not found or missing tenant information',
                    ] );
                }
                $tenantId = $cartTemp->tenant_id;
            }

            $tenant = Tenant::find( $tenantId );
            if ( !$tenant ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Tenant not found',
                ] );
            }

            // Get connection name using CrossTenantQueryService approach
            $connectionName = 'tenant_' . $tenant->id;
            $databaseName = 'sosanik_tenant_' . $tenant->id;

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
            $product = CrossTenantQueryService::getSingleFromTenant(
                $tenantId,
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

            foreach ( $datas as $data ) {

                $totalqty = collect( $data['variants'] )->sum( 'qty' );

                $is_unlimited = 1;
                if ( $cart->purchase_type == 'single' || $product->is_connect_bulk_single == 1 ) {
                    // Get current product qty using DB facade with CrossTenantQueryService connection
                    $currentProduct = DB::connection($connectionName)->table('products')
                        ->where('id', $productid)
                        ->first();

                    if ($currentProduct) {
                        // Update product quantity using DB facade directly to avoid tenant attributes
                        DB::connection($connectionName)->table('products')
                            ->where('id', $productid)
                            ->update(['qty' => $currentProduct->qty - $totalqty]);
                    }

                    // Update product variants from product's tenant database
                    if (isset($data['variants']) && is_array($data['variants'])) {
                        foreach ( $data['variants'] as $variant ) {
                            if (!isset($variant['variant_id'])) {
                                continue;
                            }

                            $currentVariant = DB::connection($connectionName)->table('product_variants')
                                ->where('id', $variant['variant_id'])
                                ->first();

                            if ($currentVariant && isset($variant['qty'])) {
                                // Update variant qty using DB facade directly
                                DB::connection($connectionName)->table('product_variants')
                                    ->where('id', $variant['variant_id'])
                                    ->update(['qty' => $currentVariant->qty - $variant['qty']]);
                            }
                        }
                    }

                    // Update product variants array from product's tenant database
                    $databaseValue = DB::connection($connectionName)->table('products')
                        ->where('id', $productid)
                        ->first();

                    if ( $databaseValue && $databaseValue->variants != '' ) {
                        $variantsArray = is_string($databaseValue->variants)
                            ? json_decode($databaseValue->variants, true)
                            : $databaseValue->variants;

                        $result = [];
                        if (is_array($variantsArray) && isset($data['variants']) && is_array($data['variants'])) {
                            foreach ( $variantsArray as $dbItem ) {
                                foreach ( $data['variants'] as $userItem ) {
                                    if (isset($userItem['variant_id']) && isset($dbItem['id']) && isset($userItem['qty'])) {
                                        if ( $dbItem['id'] == $userItem['variant_id'] ) {
                                            $dbItem['qty'] -= $userItem['qty'];
                                            break;
                                        }
                                    }
                                }
                                $result[] = $dbItem;
                            }
                        }

                        // Update variants using DB facade directly
                        DB::connection($connectionName)->table('products')
                            ->where('id', $productid)
                            ->update(['variants' => json_encode($result)]);
                    }
                    $is_unlimited = 0;
                }

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

                // Generate unique order_id using the tenant connection
                $orderId = self::generateUniqueOrderId($connectionName);

                // Create order directly in the tenant database (not using CrossTenantQueryService)
                $order = new Order();
                $order->setConnection($connectionName);
                $order->order_id            = $orderId;
                $order->vendor_id           = $product->user_id;
                $order->affiliator_id       = $userid;
                $order->product_id          = $product->id;
                $order->name                = $data['name'];
                $order->phone               = $data['phone'];
                $order->email               = $data['email'];
                $order->city                = $data['city'];
                $order->address             = $data['address'];
                $order->variants            = json_encode( $data['variants'] );
                $order->afi_amount          = $afi_amount;
                $order->product_amount      = $totalAmount;
                $order->due_amount          = $totalDue;
                $order->status              = $status;
                $order->category_id         = $categoryId;
                $order->qty                 = $totalqty;
                $order->totaladvancepayment = $totaladvancepayment;
                $order->is_unlimited        = $is_unlimited;
                $order->delivery_charge     = $deliveryCharge;
                $order->tenant_id           = $tenant->id;
                $order->save();

                // Check courier using CrossTenantQueryService
                $courierCredentials = CrossTenantQueryService::queryTenant(
                    $tenant,
                    CourierCredential::class,
                    function ( $query ) use ( $product ) {
                        $query->where( 'vendor_id', $product->vendor_id ?? $product->user_id )
                            ->where( 'status', 'active' )
                            ->where( 'default', 'yes' );
                    }
                );

                $checkCourier = $courierCredentials->isNotEmpty();
                $isPathao = false;
                $isRedx = false;

                if ( $checkCourier ) {
                    $isPathao = CrossTenantQueryService::queryTenant(
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

                    if ( $order ) {
                        // Create OrderDeliveryToCourier using CrossTenantQueryService
                        CrossTenantQueryService::saveToTenant(
                            $tenantId,
                            OrderDeliveryToCourier::class,
                            function ( $model ) use ( $order, $product, $userid, $data, $isPathao, $isRedx ) {
                                $model->order_id            = $order->id;
                                $model->vendor_id           = $product->user_id;
                                $model->affiliator_id       = $userid;
                                $model->merchant_order_id   = $order->order_id;
                                $model->recipient_name      = $data['name'];
                                $model->recipient_phone     = $data['phone'];
                                $model->recipient_address   = $data['address'];
                                $model->courier_id          = $data['courier_id'];
                                $model->item_weight         = $data['item_weight'];
                                $model->recipient_city      = $isPathao ? $data['city_id'] : null;
                                $model->recipient_zone      = $isPathao ? $data['zone_id'] : null;
                                $model->recipient_area      = $isPathao ? $data['area_id'] : ( $isRedx ? $data['area_id'] : null );
                                $model->area_name           = $isRedx ? $data['area_name'] : null;
                                $model->delivery_type       = 48;
                                $model->item_type           = $data['item_type'];
                                $model->special_instruction = $data['special_instruction'];
                                $model->item_quantity       = $data['item_quantity'];
                                $model->amount_to_collect   = $order->due_amount;
                                $model->item_description    = $data['item_description'];
                            }
                        );
                    }
                }

                // Removed commented code block

                if ( $totaladvancepayment > 0 && $order ) {
                    // Create AdvancePayment using CrossTenantQueryService
                    CrossTenantQueryService::saveToTenant(
                        $tenantId,
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

                if ( $order ) {
                    // Create PendingBalance using CrossTenantQueryService
                    CrossTenantQueryService::saveToTenant(
                        $tenantId,
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
                }
            }

            PaymentHistoryService::store( uniqid(), ( $cart->advancepayment * $totalquantity ), $paymentprocess, 'Advance payment', '-', '', $userid );

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
}
