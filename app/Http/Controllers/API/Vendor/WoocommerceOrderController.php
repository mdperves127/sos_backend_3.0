<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\CourierCredential;
use App\Models\Order;
use App\Models\OrderDeliveryToCourier;
use App\Models\OrderDetails;
use App\Models\WoocommerceCredential;
use App\Services\PathaoService;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WoocommerceOrderController extends Controller {
    protected Client $client;

    public function __construct( Client $client ) {
        $this->client = $client;
    }

    private function wcCredential(): ?array {
        $credential = WoocommerceCredential::where( 'vendor_id', vendorId() )->first();

        if ( !$credential ) {
            return null;
        }

        return [
            'wc_key'    => $credential->wc_key,
            'wc_secret' => $credential->wc_secret,
            'wc_url'    => $credential->wc_url,
        ];
    }

    public function index(): JsonResponse {
        try {

            $credentials = $this->wcCredential();
            if (!$credentials) {
                return response()->json(['error' => 'No credentials found for this vendor.'], 404);
            }

            // Initialize pagination parameters
            $perPage = 10;
            $page = request()->get('page', 1);


            // Make the API request with pagination
            $response = $this->client->request('GET', $credentials['wc_url'] . '/wp-json/wc/v3/orders', [
                'auth' => [$credentials['wc_key'], $credentials['wc_secret']],
                'query' => [
                    'per_page' => $perPage,
                    'page' => $page,
                ],
            ]);

            if (!in_array($response->getStatusCode(), [200, 201])) {
                return response()->json(['error' => 'Failed to retrieve orders. Status code: ' . $response->getStatusCode()], $response->getStatusCode());
            }

            // Parse response data
            $orders = json_decode($response->getBody()->getContents(), true);




            // Total pages and total count (use WooCommerce headers)
            $totalCount = $response->getHeaderLine('X-WP-Total');
            $totalPages = $response->getHeaderLine('X-WP-TotalPages');


            if($totalPages > 1)
            {
                for($i = 1; $i <= $totalPages; $i++)
                {
                    $responseOrderFetch = $this->wcOrderFetch($credentials, $i, 10);
                }
            }

            $totalOrders = Order::where('vendor_id', vendorId())->get();

            return response()->json( [
                'status'  => 200,
                'data'   => $totalOrders,
                'data_count' => count( $totalOrders ),
                'message' => "Successfully synced",
            ] );

            /**
             * The following code is the old code that was replaced by the above code.
             * The above code is more efficient and fetches all orders from all pages.
             */

            // Generate next and previous links
            // $baseUrl = request()->url();
            // $nextPage = ($page < $totalPages) ? $baseUrl . '?page=' . ($page + 1) . '&per_page=' . $perPage : null;
            // $prevPage = ($page > 1) ? $baseUrl . '?page=' . ($page - 1) . '&per_page=' . $perPage : null;

            // // Return paginated response
            // return response()->json([
            //     'data' => $orders,
            //     'pagination' => [
            //         'current_page' => (int)$page,
            //         'per_page' => $perPage,
            //         'total_count' => (int)$totalCount,
            //         'total_pages' => (int)$totalPages,
            //         'next_page' => $nextPage,
            //         'prev_page' => $prevPage,
            //     ],
            // ]);

            // ============================ Total Order Fetch and Pagination ============================


            // ============================ old code ============================

            //     $credentials = $this->wcCredential();
            //     if ( !$credentials ) {
            //         return response()->json( ['error' => 'No credentials found for this vendor.'], 404 );
            //     }

            //     $response = $this->client->request( 'GET', $credentials['wc_url'] . '/wp-json/wc/v3/orders?per_page=100', [
            //         'auth' => [$credentials['wc_key'], $credentials['wc_secret']],
            //     ] );

            //     if ( !in_array( $response->getStatusCode(), [200, 201] ) ) {
            //         return response()->json( ['error' => 'Failed to retrieve orders. Status code: ' . $response->getStatusCode()], $response->getStatusCode() );
            //     }

            //     $orders = json_decode( $response->getBody()->getContents(), true );


            //     foreach ( $orders as $orderData ) {
            //         try {
            //             $orderNumber = 'WC-' . ( $orderData['number'] ?? '0' );

            //             $existingOrder = Order::where( 'wc_order_no', $orderNumber )->first();

            //             if ( $existingOrder ) {
            //                 error_log( "Order with order_id $orderNumber already exists, skipping." );
            //                 continue;
            //             }

            //             $order            = new Order();
            //             $order->vendor_id = vendorId();

            //             if ( isset( $orderData['billing'] ) ) {
            //                 $billing        = $orderData['billing'];
            //                 $order->name    = ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' );
            //                 $order->email   = $billing['email'] ?? '';
            //                 $order->phone   = substr( $billing['phone'], 3 ) ?? '00000000';
            //                 $order->address = ( $billing['address_1'] ?? '' ) . ', ' . ( $billing['address_2'] ?? '' );
            //                 $order->city    = $billing['city'] ?? '';
            //             } else {
            //                 $order->name    = 'unknown';
            //                 $order->email   = 'unknown';
            //                 $order->phone   = '00000000';
            //                 $order->address = 'unknown';
            //                 $order->city    = 'unknown';
            //             }

            //             $order->wc_order_no    = $orderNumber;
            //             $order->product_amount = $orderData['total'] ?? 0;
            //             $order->due_amount     = $orderData['payment_method'] == "cod" ? $orderData['total'] : 0;
            //             $order->order_media    = 'Woocommerce';
            //             $order->status         = 'pending';
            //             $order->last_status    = 'pending';
            //             $order->category_id    = 0;
            //             $order->custom_order   = 1;
            //             $order->qty            = getWcItemTotalQty( $orderData );
            //             $order->save();

            //             if ( isset( $orderData['line_items'] ) ) {
            //                 foreach ( $orderData['line_items'] as $orderItemData ) {
            //                     $orderItem           = new OrderDetails();
            //                     $orderItem->order_id = $order->id;

            //                     $orderItem->product_id = $orderItemData['product_id'] ?? null;

            //                     if ( is_null( $orderItem->product_id ) ) {
            //                         error_log( "Missing product_id for order " . $order->order_id );
            //                         continue; // Skip if product_id is missing
            //                     }

            //                     $orderItem->sub_qty   = $orderItemData['quantity'] ?? 0;
            //                     $orderItem->rate      = $orderItemData['price'] ?? 0;
            //                     $orderItem->sub_total = $orderItemData['subtotal'] ?? 0;
            //                     $orderItem->save();
            //                 }
            //             }
            //         } catch ( \Exception $e ) {
            //             return response()->json( ['error' => 'An error occurred: ' . $e->getMessage()], 500 );
            //         }
            //     }

            //     return response()->json( [
            //         'status'  => 200,
            //         'data'   => $orders,
            //         'data_count' => count( $orders ),
            //         'message' => "Successfully synced",
            //     ] );

        } catch ( Exception $e ) {
            error_log( $e->getMessage() );
            return response()->json( ['error' => 'An error occurred: ' . $e->getMessage()], 500 );
        }

        // ============================ old code ============================
    }

    public function wcOrderFetch($credential, $pageNumber = 1, $perPage = 10) {
        $response = $this->client->request('GET', $credential['wc_url'] . '/wp-json/wc/v3/orders', [
            'auth' => [$credential['wc_key'], $credential['wc_secret']],
            'query' => [
                'per_page' => $perPage,
                'page' => $pageNumber,
            ],
        ]);

        if (!in_array($response->getStatusCode(), [200, 201])) {
            return response()->json(['error' => 'Failed to retrieve orders. Status code: ' . $response->getStatusCode()], $response->getStatusCode());
        }

        $orders = json_decode( $response->getBody()->getContents(), true );

        foreach ( $orders as $orderData ) {
            try {
                $orderNumber = 'WC-' . ( $orderData['number'] ?? '0' );

                $existingOrder = Order::where( 'wc_order_no', $orderNumber )->first();

                if ( $existingOrder ) {
                    error_log( "Order with order_id $orderNumber already exists, skipping." );
                    continue;
                }

                $order            = new Order();
                $order->vendor_id = vendorId();

                if ( isset( $orderData['billing'] ) ) {
                    $billing        = $orderData['billing'];
                    $order->name    = ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' );
                    $order->email   = $billing['email'] ?? '';
                    $order->phone   = substr( $billing['phone'], 3 ) ?? '00000000';
                    $order->address = ( $billing['address_1'] ?? '' ) . ', ' . ( $billing['address_2'] ?? '' );
                    $order->city    = $billing['city'] ?? '';
                } else {
                    $order->name    = 'unknown';
                    $order->email   = 'unknown';
                    $order->phone   = '00000000';
                    $order->address = 'unknown';
                    $order->city    = 'unknown';
                }

                $order->wc_order_no    = $orderNumber;
                $order->product_amount = $orderData['total'] ?? 0;
                $order->due_amount     = $orderData['payment_method'] == "cod" ? $orderData['total'] : 0;
                $order->order_media    = 'Woocommerce';

                if ( $orderData['status'] == 'pending' || $orderData['status'] == 'on-hold' ) {
                    $status = 'pending';
                } elseif ( $orderData['status'] == 'processing' ) {
                    $status = 'processing';
                } elseif ( $orderData['status'] == 'completed' ) {
                    $status = 'delivered';
                } elseif ( $orderData['status'] == 'cancelled' ) {
                    $status = 'cancel';
                }elseif ( $orderData['status'] == 'refunded' || $orderData['status'] == 'failed' ) {
                    $status = 'return';
                } else {
                    $status = 'pending';
                }



                $order->status         = $status;
                $order->last_status    = $status;
                $order->category_id    = 0;
                $order->custom_order   = 1;
                $order->qty            = getWcItemTotalQty( $orderData );
                $order->save();

                if ( isset( $orderData['line_items'] ) ) {
                    foreach ( $orderData['line_items'] as $orderItemData ) {
                        $orderItem           = new OrderDetails();
                        $orderItem->order_id = $order->id;

                        $orderItem->product_id = $orderItemData['product_id'] ?? null;

                        if ( is_null( $orderItem->product_id ) ) {
                            error_log( "Missing product_id for order " . $order->order_id );
                            continue; // Skip if product_id is missing
                        }

                        $orderItem->sub_qty   = $orderItemData['quantity'] ?? 0;
                        $orderItem->rate      = $orderItemData['price'] ?? 0;
                        $orderItem->sub_total = $orderItemData['subtotal'] ?? 0;
                        $orderItem->save();
                    }
                }
            } catch ( \Exception $e ) {
                return response()->json( ['error' => 'An error occurred: ' . $e->getMessage()], 500 );
            }
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    public function wcOrderDelivery( Request $request, $orderId ) {

        try {
            $order = Order::where( 'id', $orderId )->first();

            $checkCourier = CourierCredential::where( [
                'vendor_id' => $order->vendor_id,
                'status'    => "active",
            ] )->exists();

            if ( $checkCourier ) {

                $validator = Validator::make( $request->all(), [
                    'phone'       => 'required',
                    'address'     => 'required',
                    'city_id'     => 'required',
                    'zone_id'     => 'required',
                    'area_id'     => 'required',
                    'item_weight' => 'required',
                    'item_type'   => 'required',
                ] );

                if ( $validator->fails() ) {
                    return response()->json( [
                        'status'            => 400,
                        'validation_errors' => $validator->messages(),
                    ] );
                }

                OrderDeliveryToCourier::create( [
                    'order_id'            => $order->id,
                    'vendor_id'           => $order->vendor_id,
                    'affiliator_id'       => $order->affiliator_id,
                    'merchant_order_id'   => $order->order_id,
                    'recipient_name'      => $order->name ?? "unknow",
                    'recipient_phone'     => $request->phone,
                    'recipient_address'   => $request->address,
                    'courier_id'          => $request->courier_id,
                    'item_weight'         => $request->item_weight,
                    'recipient_city'      => $request->city_id,
                    'recipient_zone'      => $request->zone_id,
                    'recipient_area'      => $request->area_id,
                    'delivery_type'       => 48,
                    'item_type'           => $request->item_type,
                    'special_instruction' => $request->special_instruction,
                    'item_quantity'       => $order->qty,
                    'amount_to_collect'   => $order->due_amount,
                    'item_description'    => $request->item_description,

                ] );

                $courierOrder = OrderDeliveryToCourier::where( ['order_id' => $order->id, 'merchant_order_id' => $order->order_id] )->first();

                // return $courierOrder;
                if ( $courierOrder ) {

                    $credential = CourierCredential::where( 'vendor_id', $order->vendor_id )->first();

                    $access_token = PathaoService::getToken( $credential->api_key, $credential->secret_key, $credential->client_email, $credential->client_password );

                    if ( $access_token ) {
                        $orderToCourier = PathaoService::newOrder( $access_token, $credential->store_id, $courierOrder );
                    }

                }

                $order->update( [
                    'status'         => 'progress',
                    'last_status'    => 'progress',
                    'consignment_id' => $orderToCourier['data']['consignment_id'],
                    'courier_name'   => $courierOrder->courierCredential->courier_name,
                    'delivery_id'    => $orderToCourier['data']['consignment_id'] . "_+_" . $courierOrder->courierCredential->courier_name,
                ] );

                return response()->json( [
                    'status'         => 200,
                    'message'        => 'Order progress successfull!',
                    'consignment_id' => $orderToCourier['data']['consignment_id'],
                    'delivery_fee'   => $orderToCourier['data']['delivery_fee'],
                    'courier_name'   => $courierOrder->courierCredential->courier_name,

                ] );

            } else {
                $order->update( [
                    'status'      => 'progress',
                    'last_status' => 'progress',
                ] );

                return response()->json( [
                    'ststu'   => 200,
                    'message' => 'Order progress successfull!',
                ] );
            }

        } catch ( \Exception $e ) {
            return response()->json( $e );
        }
    }


    public function wcOrderStatusUpdate( $orderId, $status ) {

        $order = Order::where( 'id', $orderId )->first();
        if(!$order) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        $wcOrderNo = explode( "-", $order->wc_order_no )[1] ?? $order->wc_order_no;

        try {

            $credentials = $this->wcCredential();
            if (!$credentials) {
                return response()->json(['error' => 'No credentials found for this vendor.'], 404);
            }

            // Make the API request with pagination
            $response = $this->client->request('PUT', $credentials['wc_url'] . '/wp-json/wc/v3/orders/' . $wcOrderNo , [
                'auth' => [$credentials['wc_key'], $credentials['wc_secret']],
                'json' => [
                    'status' => $status,
                ],
            ]);

            if (!in_array($response->getStatusCode(), [200, 201])) {
                return response()->json(['error' => 'Failed to retrieve orders. Status code: ' . $response->getStatusCode()], $response->getStatusCode());
            }

            // Parse response data
            $order = json_decode($response->getBody(), true);

            return response()->json( [
                'status'  => 200,
                'data'   => $order,
                'message' => "Successfully synced",
            ] );

        } catch ( Exception $e ) {
            error_log( $e->getMessage() );
            return response()->json( ['error' => 'An error occurred: ' . $e->getMessage()], 500 );
        }

    }

}
