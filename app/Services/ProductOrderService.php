<?php

namespace App\Services;

use App\Models\AdvancePayment;
use App\Models\CancelOrderBalance;
use App\Models\CourierCredential;
use App\Models\Order;
use App\Models\OrderDeliveryToCourier;
use App\Models\OrderDetails;
use App\Models\PendingBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WoocommerceCredential;
use App\Services\PathaoService;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;

/**
 * Class ProductOrderService.
 */
class ProductOrderService {
    static function orderStatus( $validatedData, $id ) {
        $order = Order::find( $id );

        return match ( $validatedData['status'] ) {
            'pending' => self::pendingdOrder( $order ),
            'cancel' => self::canceldOrder( $order ),
            'progress' => self::progressOrder( $order ),
            'delivered' => self::deliveredOrder( $order ),
            'return' => self::returnOrder( $order ),
            'received' => self::receivedOrder( $order ),
            'ready' => self::productReady( $order ),
            'processing' => self::orderPreocessing( $order ),
        };
    }

    static function canceldOrder( $order ) {

        DB::beginTransaction();
        try {

            return $order;
            $orderId = $order->id ?? null;

            $order->reason = request( 'reason' );
            // $order->update( ['status' => 'cancel'] );

            if ( $order->productVariant == NULL ) {

                // order current status
                if ( $order->status != 'hold' && $order->order_media == "null" ) {
                    self::vendorBalanceBack( $order );
                }

                // affiliate balance back
                $order = Order::where( 'id', $orderId )->first();

                return json_decode( $order->variants, true );

                // if ( $order->order_media == "null" ) {
                //     self::affiliateBalanceback( $order );
                //     self::quantityadded( $order );
                // }
            } else {
                $orderDetails        = OrderDetails::where( 'order_id', $orderId )->get();
                $processedProductIds = [];
                foreach ( $orderDetails as $orderDetail ) {
                    Product::where( 'id', $orderDetail->product_id )->increment( 'qty', $orderDetail->sub_qty );

                    if ( !in_array( $orderDetail->product_id, $processedProductIds ) ) {
                        ProductVariant::where( 'product_id', $orderDetail->product_id )->increment( 'qty', $orderDetail->sub_qty );
                        $processedProductIds[] = $orderDetail->product_id;
                    }
                }
            }

            if ( $order->wc_order_no != null ) {
                $wcOrderNo = explode( "-", $order->wc_order_no )[1];
                if ( $wcOrderNo ) {
                    $data = self::wocommerceOrderStatusUpdate( $order->id, 'cancel', 'cancelled' );
                }
            }

            DB::commit();
            return self::response( 'Order cancel successfull' );

        } catch ( \Exception $e ) {
            DB::rollBack();
            return response()->json( [
                'status'  => 400,
                'message' => $e->getMessage(),
            ] );
        }
    }

    static function affiliateBalanceback( $order ) {
        $advancepayment = AdvancePayment::where( 'order_id', $order->id )->first();

        if ( $advancepayment ) {
            CancelOrderBalance::create( [
                'user_id' => $order->affiliator_id,
                'balance' => $advancepayment->amount,
            ] );
        }
    }

    static function vendorBalanceBack( $order ) {
        $pendingBalance = self::orderPendingBalance( $order );

        CancelOrderBalance::create( [
            'user_id' => $order->vendor_id,
            'balance' => $pendingBalance->amount,
        ] );
    }

    static function pendingdOrder( $order ) {

        $order->update( ['status' => 'pending', 'last_status' => 'pending'] );

        if ( $order->wc_order_no != null ) {
            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
            if ( $wcOrderNo ) {
                $data = self::wocommerceOrderStatusUpdate( $order->id, 'pending', 'pending' );
            }
        }

        return self::response( 'Order pending successfull!' );
    }

    static function receivedOrder( $order ) {

        $order->update( ['status' => 'received', 'last_status' => 'received'] );

        if ( $order->wc_order_no != null ) {
            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
            if ( $wcOrderNo ) {
                $data = self::wocommerceOrderStatusUpdate( $order->id, 'received', 'processing' );
            }
        }

        // $vendor = User::find( $order->vendor_id );
        $vendor = Tenant::on('mysql')->find( tenant()->id );
        if ( $order->custom_order == 0 ) {
            $vendor->decrement( 'balance', $order->afi_amount );
        }

        PaymentHistoryService::store( uniqid(), $order->afi_amount, 'My wallet', 'Affiliate commission', '-', '', $order->vendor_id );

        // $vendor_balance->balance = ( $vendor_balance->balance - $afi_amount );
        // $vendor_balance->save();
        return self::response( 'Order received successfull!' );
    }

    // Product ready

    static function productReady( $order ) {
        $order->update( ['status' => 'ready', 'last_status' => 'ready'] );

        if ( $order->wc_order_no != null ) {
            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
            if ( $wcOrderNo ) {
                $data = self::wocommerceOrderStatusUpdate( $order->id, 'ready', 'processing' );
            }
        }

        return self::response( 'Order ready successfull!' );
    }

    // Product progress

    static function progressOrder( $order ) {

        $checkCourier = CourierCredential::where( [
            'vendor_id' => $order->vendor_id,
            'status'    => "active",
            'default'   => "yes",
        ] )->exists();

        if ( $checkCourier ) {

            $courierOrder = OrderDeliveryToCourier::where( ['order_id' => $order->id, 'merchant_order_id' => $order->order_id] )->first();

            // return $courierOrder;
            if ( $courierOrder ) {

                $credential = CourierCredential::where( 'vendor_id', $order->vendor_id )->where( 'status', 'active' )->where( 'default', 'yes' )->first();

                if ( $credential->courier_name == 'pathao' ) {
                    $access_token = PathaoService::getToken( $credential->api_key, $credential->secret_key, $credential->client_email, $credential->client_password );
                    if ( $access_token ) {
                        $orderToCourier = PathaoService::newOrder( $access_token, $credential->store_id, $courierOrder );

                        $order->update( [
                            'status'         => 'progress',
                            'last_status'    => 'progress',
                            'consignment_id' => $orderToCourier['data']['consignment_id'],
                            'courier_name'   => $courierOrder->courierCredential->courier_name,
                            'delivery_id'    => $orderToCourier['data']['consignment_id'] . "_+_" . $courierOrder->courierCredential->courier_name,
                        ] );

                        if ( $order->wc_order_no != null ) {
                            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
                            if ( $wcOrderNo ) {
                                $data = self::wocommerceOrderStatusUpdate( $order->id, 'progress', 'processing' );
                            }
                        }

                        return response()->json( [
                            'status'         => 200,
                            'message'        => 'Order progress successfull!',
                            'consignment_id' => $orderToCourier['data']['consignment_id'],
                            'delivery_fee'   => $orderToCourier['data']['delivery_fee'],
                            'courier_name'   => $courierOrder->courierCredential->courier_name,

                        ] );
                    }

                } elseif ( $credential->courier_name == 'steadfast' ) {

                    $orderToCourier = SteadFastService::order( $credential->api_key, $credential->secret_key, $courierOrder );

                    if ( $orderToCourier ) {
                        if ( isset( $orderToCourier['status'] ) && $orderToCourier['status'] === 200 && isset( $orderToCourier['consignment'] ) ) {
                            $consignment = $orderToCourier['consignment'];

                            $order->update( [
                                'status'         => 'progress',
                                'last_status'    => 'progress',
                                'consignment_id' => $consignment['consignment_id'],
                                'courier_name'   => $courierOrder->courierCredential->courier_name,
                                'delivery_id'    => $consignment['consignment_id'] . "_+_" . $courierOrder->courierCredential->courier_name,
                            ] );

                            if ( $order->wc_order_no !== null ) {
                                $wcOrderNo = explode( "-", $order->wc_order_no )[1] ?? null;
                                if ( $wcOrderNo ) {
                                    $data = self::wocommerceOrderStatusUpdate( $order->id, 'progress', 'processing' );
                                }
                            }

                            return response()->json( [
                                'status'         => 200,
                                'message'        => 'Order progress successful!',
                                'consignment_id' => $consignment['consignment_id'],
                                'delivery_fee'   => $consignment['cod_amount'],
                                'courier_name'   => $courierOrder->courierCredential->courier_name,
                            ] );
                        }
                    }

                } elseif ( $credential->courier_name == 'redx' ) {

                    $access_token = CourierCredential::where( 'vendor_id', vendorId() )->where( 'courier_name', 'redx' )->first();

                    if ( $access_token ) {

                        $orderToCourier = RedxService::newOrderRedx( $access_token->api_key, $courierOrder );
                        $order->update( [
                            'status'         => 'progress',
                            'last_status'    => 'progress',
                            'consignment_id' => $orderToCourier['tracking_id'],
                            'courier_name'   => $courierOrder->courierCredential->courier_name,
                            'delivery_id'    => $orderToCourier['tracking_id'] . "_+_" . $courierOrder->courierCredential->courier_name,
                        ] );

                        if ( $order->wc_order_no != null ) {
                            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
                            if ( $wcOrderNo ) {
                                $data = self::wocommerceOrderStatusUpdate( $order->id, 'progress', 'processing' );
                            }
                        }

                        return response()->json( [
                            'status'         => 200,
                            'message'        => 'Order progress successfull!',
                            'consignment_id' => $orderToCourier['tracking_id'],
                            'courier_name'   => $courierOrder->courierCredential->courier_name,

                        ] );
                    }

                } else {
                    return response()->json( [
                        'status'  => 400,
                        'message' => 'Courier credential not found!',
                    ] );
                }

                // $credential   = CourierCredential::where( 'vendor_id', $order->vendor_id )->first();
                // $access_token = PathaoService::getToken( $credential->api_key, $credential->secret_key, $credential->client_email, $credential->client_password );
                // if ( $access_token ) {
                //     $orderToCourier = PathaoService::newOrder( $access_token, $credential->store_id, $courierOrder );
                // }

            }

            // $order->update( [
            //     'status'         => 'progress',
            //     'last_status'    => 'progress',
            //     'consignment_id' => $orderToCourier['data']['consignment_id'],
            //     'courier_name'   => $courierOrder->courierCredential->courier_name,
            //     'delivery_id'    => $orderToCourier['data']['consignment_id'] . "_+_" . $courierOrder->courierCredential->courier_name,
            // ] );

            // return response()->json( [
            //     'status'         => 200,
            //     'message'        => 'Order progress successfull!',
            //     'consignment_id' => $orderToCourier['data']['consignment_id'],
            //     'delivery_fee'   => $orderToCourier['data']['delivery_fee'],
            //     'courier_name'   => $courierOrder->courierCredential->courier_name,

            // ] );
        } else {
            $order->update( [
                'status'      => 'progress',
                'last_status' => 'progress',
            ] );

            if ( $order->wc_order_no != null ) {
                $wcOrderNo = explode( "-", $order->wc_order_no )[1];
                if ( $wcOrderNo ) {
                    $data = self::wocommerceOrderStatusUpdate( $order->id, 'progress', 'processing' );
                }
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Order progress successfull!',

            ] );
        }

    }

    static function orderPreocessing( $order ) {

        $order->update( ['status' => 'processing', 'last_status' => 'processing'] );

        if ( $order->wc_order_no != null ) {
            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
            if ( $wcOrderNo ) {
                $data = self::wocommerceOrderStatusUpdate( $order->id, 'processing', 'processing' );
            }
        }

        return self::response( 'Order processing successfull!' );
    }

    static function returnOrder( $order ) {
        $order->reason = request( 'reason' );
        $order->update( ['status' => 'return', 'last_status' => 'return'] );

        if ( $order->wc_order_no != null ) {
            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
            if ( $wcOrderNo ) {
                $data = self::wocommerceOrderStatusUpdate( $order->id, 'return', 'refunded' );
            }
        }

        // order current status
        if ( $order->status != 'hold' ) {
            self::vendorBalanceBack( $order );
        }

        // affiliate balance back
        self::affiliateBalanceback( $order );

        return self::response( 'Order retrun successfull!' );
    }

    static function quantityadded( $order ) {
        $balance = PendingBalance::where( 'order_id', $order->id )->first();

        if ( $order->is_unlimited != 1 ) {
            $product      = Product::find( $order->product_id );
            $product->qty = ( $product->qty + $balance->qty );

            $variants = json_decode( $order->variants );
            $data     = collect( $variants )->pluck( 'qty', 'variant_id' );

            $result = [];

            foreach ( $data as $variantId => $qty ) {
                $result[] = [
                    "variant_id" => $variantId,
                    "qty"        => $qty,
                ];
            }

            $databaseValues = $product->variants;
            $userValues     = $result;

            if ( $databaseValues != '' ) {
                foreach ( $databaseValues as &$databaseItem ) {
                    $variantId         = $databaseItem['id'];
                    $matchingUserValue = collect( $userValues )->firstWhere( 'variant_id', $variantId );

                    if ( $matchingUserValue ) {
                        $userQty = $matchingUserValue['qty'];
                        $databaseItem['qty'] += $userQty;
                    }
                }

                $product->variants = $databaseValues;
            }

            $product->save();
        }
    }

    static function deliveredOrder( $order ) {

        if ( $order->order_media == null ) {
            $affiliateData = self::orderPendingBalance( $order );

            $affiliator = User::find( $affiliateData->affiliator_id );
            $affiliator->increment( 'balance', $affiliateData->amount );
            $affiliateData->update( ['status' => 'success'] );
            PaymentHistoryService::store( uniqid(), $affiliateData->amount, 'My wallet', 'Product commission', '+', '', $affiliator->id );

            $vendor = User::find( $order->vendor_id );
            $vendor->increment( 'balance', $order->totaladvancepayment );
            PaymentHistoryService::store( uniqid(), $order->totaladvancepayment, 'My wallet', 'Order advance', '+', '', $vendor->id );

        }

        $order->update( ['status' => 'delivered', 'last_status' => 'delivered'] );

        if ( $order->wc_order_no != null ) {
            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
            if ( $wcOrderNo ) {
                $data = self::wocommerceOrderStatusUpdate( $order->id, 'delevered', 'completed' );
            }
        }

        return self::response( 'Order delivered successfully' );
    }

    static function orderPendingBalance( $order ) {
        return PendingBalance::where( 'order_id', $order->id )->first();
    }

    static function response( $message ) {
        return response()->json( [
            'status'  => 200,
            'message' => $message,
        ] );
    }

    protected function wocommerceOrderStatusUpdate( $orderId, $systemStatus, $wcStatus ) {
        $order = Order::where( 'id', $orderId )->first();
        if ( !$order ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Order not found!',
            ] );
        }

        $wcOrderNo = explode( "-", $order->wc_order_no )[1];
        if ( !$wcOrderNo ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'WooCommerce order number not found.',
            ] );
        }

        $client = new Client();

        try {

            $credentials = $credential = WoocommerceCredential::where( 'vendor_id', vendorId() )->first();
            if ( !$credentials ) {
                return response()->json( ['error' => 'No credentials found for this vendor.'], 404 );
            }

            // Make the API request with pagination
            $response = $client->request( 'PUT', $credentials->wc_url . '/wp-json/wc/v3/orders/' . $wcOrderNo, [
                'auth' => [$credentials->wc_key, $credentials->wc_secret],
                'json' => [
                    'status' => $wcStatus,
                ],
            ] );

            if ( !in_array( $response->getStatusCode(), [200, 201] ) ) {
                return response()->json( ['error' => 'Failed to retrieve orders. Status code: ' . $response->getStatusCode()], $response->getStatusCode() );
            }

            return true;

            // Parse response data
            $order = json_decode( $response->getBody(), true );

            return response()->json( [
                'status'  => 200,
                'data'    => $order,
                'message' => "Successfully synced",
            ] );

        } catch ( Exception $e ) {
            return false;
            // error_log( $e->getMessage() );
            // return response()->json( ['error' => 'An error occurred: ' . $e->getMessage()], 500 );
        }
    }

}
