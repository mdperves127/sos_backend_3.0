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

            // Get cart from tenant's database using CrossTenantQueryService
            $cart = CrossTenantQueryService::getSingleFromTenant(
                $tenantId,
                Cart::class,
                function ( $query ) use ( $cartId ) {
                    $query->where( 'id', $cartId );
                }
            );

            if ( !$cart ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Cart not found',
                ] );
            }

            // Get product from tenant's database using CrossTenantQueryService
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
                    // Update product quantity - get product, modify, then save using service
                    $updatedProduct = CrossTenantQueryService::getSingleFromTenant(
                        $tenantId,
                        Product::class,
                        function ( $query ) use ( $productid ) {
                            $query->where( 'id', $productid );
                        }
                    );

                    if ( $updatedProduct ) {
                        $updatedProduct->qty -= $totalqty;
                        $updatedProduct->setConnection( $updatedProduct->getConnectionName() );
                        $updatedProduct->save();
                    }

                    // Update product variants
                    foreach ( $data['variants'] as $variant ) {
                        $productVariant = CrossTenantQueryService::getSingleFromTenant(
                            $tenantId,
                            ProductVariant::class,
                            function ( $query ) use ( $variant ) {
                                $query->where( 'id', $variant['variant_id'] );
                            }
                        );

                        if ( $productVariant ) {
                            $productVariant->qty -= $variant['qty'];
                            $productVariant->setConnection( $productVariant->getConnectionName() );
                            $productVariant->save();
                        }
                    }

                    // Update product variants array
                    $result = [];
                    $databaseValue = CrossTenantQueryService::getSingleFromTenant(
                        $tenantId,
                        Product::class,
                        function ( $query ) use ( $productid ) {
                            $query->where( 'id', $productid );
                        }
                    );

                    if ( $databaseValue && $databaseValue->variants != '' ) {
                        foreach ( $databaseValue->variants as $dbItem ) {
                            foreach ( $data['variants'] as $userItem ) {
                                if ( $dbItem['id'] == $userItem['variant_id'] ) {
                                    $dbItem['qty'] -= $userItem['qty'];
                                    break;
                                }
                            }
                            $result[] = $dbItem;
                        }

                        $databaseValue->variants = $result;
                        $databaseValue->setConnection( $databaseValue->getConnectionName() );
                        $databaseValue->save();
                    }
                    $is_unlimited = 0;
                }

                // Get vendor balance from tenant database
                $vendor_balance = CrossTenantQueryService::getSingleFromTenant(
                    $tenantId,
                    User::class,
                    function ( $query ) use ( $product ) {
                        $query->where( 'id', $product->user_id );
                    }
                );

                $afi_amount = $totalqty * $cart->amount;

                if ( $vendor_balance && $vendor_balance->balance >= $afi_amount ) {
                    $status = Status::Pending->value;
                    // $vendor_balance->balance = ( $vendor_balance->balance - $afi_amount );
                    if ( $vendor_balance->getConnectionName() ) {
                        $vendor_balance->setConnection( $vendor_balance->getConnectionName() );
                        $vendor_balance->save();
                    }
                    // PaymentHistoryService::store( uniqid(), $afi_amount, 'My wallet', 'Affiliate commission', '-', '', $product->user_id );

                } else {
                    $status = Status::Hold->value;
                }

                $totalAmount         = convertfloat( $cart->product_price ) * convertfloat( $totalqty );
                $totaladvancepayment = $cart->advancepayment * $totalqty;

                $totalDue = ( $totalAmount + $data['delivery_charge']['charge'] ) - $totaladvancepayment;

                // Create order using CrossTenantQueryService
                $order = CrossTenantQueryService::saveToTenant(
                    $tenantId,
                    Order::class,
                    function ( $model ) use ( $product, $userid, $data, $afi_amount, $cart, $totalqty, $totalAmount, $totalDue, $status, $categoryId, $totaladvancepayment, $is_unlimited ) {
                        $model->vendor_id           = $product->user_id;
                        $model->affiliator_id       = $userid;
                        $model->product_id          = $product->id;
                        $model->name                = $data['name'];
                        $model->phone               = $data['phone'];
                        $model->email               = $data['email'];
                        $model->city                = $data['city'];
                        $model->address             = $data['address'];
                        $model->variants            = json_encode( $data['variants'] );
                        $model->afi_amount          = $afi_amount;
                        $model->product_amount      = $totalAmount;
                        $model->due_amount          = $totalDue;
                        $model->status              = $status;
                        $model->category_id         = $categoryId;
                        $model->qty                 = $totalqty;
                        $model->totaladvancepayment = $totaladvancepayment;
                        $model->is_unlimited        = $is_unlimited;
                        $model->delivery_charge     = $data['delivery_charge']['charge'];
                    }
                );

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

            // Delete cart using CrossTenantQueryService - get cart first, then delete
            $cartToDelete = CrossTenantQueryService::getSingleFromTenant(
                $tenantId,
                Cart::class,
                function ( $query ) use ( $cartId ) {
                    $query->where( 'id', $cartId );
                }
            );

            if ( $cartToDelete ) {
                $cartToDelete->setConnection( $cartToDelete->getConnectionName() );
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
}
