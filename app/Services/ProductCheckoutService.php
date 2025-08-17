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
use Illuminate\Support\Facades\DB;

/**
 * Class ProductCheckoutService.
 */
class ProductCheckoutService {

    static function store( $cartId, $productid, $totalquantity, $userid, $datas, $paymentprocess = 'aamarpay' ) {

        try {
            DB::beginTransaction();

            $cart = Cart::find( $cartId );
            if ( !$cart ) {
                return false;
            }
            $product = Product::find( $productid );

            $categoryId = $cart->category_id;

            foreach ( $datas as $data ) {

                $totalqty = collect( $data['variants'] )->sum( 'qty' );

                $is_unlimited = 1;
                if ( $cart->purchase_type == 'single' || $product->is_connect_bulk_single == 1 ) {
                    $product->decrement( 'qty', $totalqty );

                    foreach ( $data['variants'] as $variant ) {
                        ProductVariant::where( 'id', $variant['variant_id'] )->decrement( 'qty', $variant['qty'] );
                    }

                    $result        = [];
                    $databaseValue = $product;

                    if ( $databaseValue->variants != '' ) {

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
                        $databaseValue->save();
                    }
                    $is_unlimited = 0;
                }

                $vendor_balance = User::find( $product->user_id );

                $afi_amount = $totalqty * $cart->amount;

                if ( $vendor_balance->balance >= $afi_amount ) {
                    $status = Status::Pending->value;
                    // $vendor_balance->balance = ( $vendor_balance->balance - $afi_amount );
                    $vendor_balance->save();
                    // PaymentHistoryService::store( uniqid(), $afi_amount, 'My wallet', 'Affiliate commission', '-', '', $product->user_id );

                } else {
                    $status = Status::Hold->value;
                }

                $totalAmount         = convertfloat( $cart->product_price ) * convertfloat( $totalqty );
                $totaladvancepayment = $cart->advancepayment * $totalqty;

                $totalDue = ( $totalAmount + $data['delivery_charge']['charge'] ) - $totaladvancepayment;

                $order = Order::create( [
                    'vendor_id'           => $product->user_id,
                    'affiliator_id'       => $userid,
                    'product_id'          => $product->id,
                    'name'                => $data['name'],
                    'phone'               => $data['phone'],
                    'email'               => $data['email'],
                    'city'                => $data['city'],
                    'address'             => $data['address'],
                    'variants'            => json_encode( $data['variants'] ),
                    'afi_amount'          => $afi_amount,
                    'product_amount'      => convertfloat( $cart->product_price ) * convertfloat( $totalqty ),
                    'due_amount'          => $totalDue,
                    'status'              => $status,
                    'category_id'         => $categoryId,
                    'qty'                 => $totalqty,
                    'totaladvancepayment' => $totaladvancepayment,
                    'is_unlimited'        => $is_unlimited,
                    'delivery_charge'     => $data['delivery_charge']['charge'],
                ] );

                $checkCourier = CourierCredential::where( ['vendor_id' => $product->vendor_id, 'status' => 'active', 'default' => 'yes'] )->exists();
                if ( $checkCourier ) {
                    $isPathao = CourierCredential::where( [
                        'vendor_id'    => $product->vendor_id,
                        'status'       => 'active',
                        'default'      => 'yes',
                        'courier_name' => 'pathao',
                    ] )->exists();

                    $isRedx = CourierCredential::where( [
                        'vendor_id'    => $product->vendor_id,
                        'status'       => 'active',
                        'default'      => 'yes',
                        'courier_name' => 'redx',
                    ] )->exists();

                    OrderDeliveryToCourier::create( [
                        'order_id'            => $order->id,
                        'vendor_id'           => $product->user_id,
                        'affiliator_id'       => $userid,
                        'merchant_order_id'   => $order->order_id,
                        'recipient_name'      => $data['name'],
                        'recipient_phone'     => $data['phone'],
                        'recipient_address'   => $data['address'],
                        'courier_id'          => $data['courier_id'],
                        'item_weight'         => $data['item_weight'],
                        'recipient_city'      => $isPathao ? $data['city_id'] : null,
                        'recipient_zone'      => $isPathao ? $data['zone_id'] : null,
                        'recipient_area'      => $isPathao ? $data['area_id'] : ( $isRedx ? $data['area_id'] : null ),
                        'area_name'           => $isRedx ? $data['area_name'] : null,
                        'delivery_type'       => 48,
                        'item_type'           => $data['item_type'],
                        'special_instruction' => $data['special_instruction'],
                        'item_quantity'       => $data['item_quantity'],
                        'amount_to_collect'   => $order->due_amount,
                        'item_description'    => $data['item_description'],
                    ] );
                }

                // $checkCourier = CourierCredential::where( [
                //     'vendor_id' => $product->vendor_id,
                //     'status'    => "active",
                // ] )->exists();

                // if ( $checkCourier ) {
                //     OrderDeliveryToCourier::create( [
                //         'order_id'            => $order->id,
                //         'vendor_id'           => $product->user_id,
                //         'affiliator_id'       => $userid,
                //         'merchant_order_id'   => $order->order_id,
                //         'recipient_name'      => $data['name'],
                //         'recipient_phone'     => $data['phone'],
                //         'recipient_address'   => $data['address'],
                //         'courier_id'          => $data['courier_id'],
                //         'item_weight'         => $data['item_weight'],
                //         'recipient_city'      => $data['city_id'],
                //         'recipient_zone'      => $data['zone_id'],
                //         'recipient_area'      => $data['area_id'],
                //         'delivery_type'       => 48,
                //         'item_type'           => $data['item_type'],
                //         'special_instruction' => $data['special_instruction'],
                //         'item_quantity'       => $data['item_quantity'],
                //         'amount_to_collect'   => $order->due_amount,
                //         'item_description'    => $data['item_description'],

                //     ] );
                // }

                if ( $totaladvancepayment > 0 ) {
                    AdvancePayment::create( [
                        'vendor_id'    => $product->user_id,
                        'affiliate_id' => $userid,
                        'product_id'   => $product->id,
                        'qty'          => $totalqty,
                        'amount'       => $totaladvancepayment,
                        'order_id'     => $order->id,
                    ] );
                }

                PendingBalance::create( [
                    'affiliator_id' => $userid,
                    'product_id'    => $product->id,
                    'order_id'      => $order->id,
                    'qty'           => $totalqty,
                    'amount'        => $afi_amount,
                    'status'        => Status::Pending->value,
                ] );
            }

            PaymentHistoryService::store( uniqid(), ( $cart->advancepayment * $totalquantity ), $paymentprocess, 'Advance payment', '-', '', $userid );

            DB::table( 'carts' )->where( 'id', $cartId )->delete();
            DB::commit();
            return response()->json( [
                'status'  => 200,
                'message' => 'Checkout successfully!',
            ] );
        } catch ( \Exception $e ) {
            DB::rollBack();
            return response()->json( [
                'status'  => 500,
                'message' => $e->getMessage(),
            ] );
        }
    }
}
