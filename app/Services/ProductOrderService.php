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
use Illuminate\Support\Facades\Schema;
use App\Models\Tenant;

/**
 * Class ProductOrderService.
 */
class ProductOrderService {
    static function orderStatus( $validatedData, $id ) {
        $order = Order::find( $id );

        if ( !$order ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Order not found.',
            ], 404 );
        }

        if ( auth()->check() && (int) $order->vendor_id !== (int) auth()->id() ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'You are not authorized to update this order.',
            ], 403 );
        }

        $status = $validatedData['status'] ?? request( 'status' );
        if ( !$status ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Status field is required.',
            ], 422 );
        }

        return match ( $status ) {
            'pending' => self::pendingdOrder( $order ),
            'cancel' => self::canceldOrder( $order ),
            'progress' => self::progressOrder( $order ),
            'delivered' => self::deliveredOrder( $order ),
            'return' => self::returnOrder( $order ),
            'received' => self::receivedOrder( $order ),
            'ready' => self::productReady( $order ),
            'processing' => self::orderPreocessing( $order ),
            default => response()->json( [
                'status'  => 422,
                'message' => 'Invalid order status.',
            ], 422 ),
        };
    }

    static function canceldOrder( $order ) {

        DB::beginTransaction();
        try {
            $orderId        = $order->id;
            $previousStatus = $order->status;

            $order->reason = request( 'reason' );
            $order->update( ['status' => 'cancel', 'last_status' => 'cancel'] );

            $orderDetails = OrderDetails::where( 'order_id', $orderId )->get();
            if ( $orderDetails->isNotEmpty() ) {
                $processedProductIds = [];
                foreach ( $orderDetails as $orderDetail ) {
                    Product::where( 'id', $orderDetail->product_id )->increment( 'qty', $orderDetail->sub_qty );

                    if ( !in_array( $orderDetail->product_id, $processedProductIds ) ) {
                        ProductVariant::where( 'product_id', $orderDetail->product_id )->increment( 'qty', $orderDetail->sub_qty );
                        $processedProductIds[] = $orderDetail->product_id;
                    }
                }
            } elseif ( $previousStatus !== 'hold' ) {
                if ( self::isDropshipperOrderMedia( $order ) ) {
                    self::vendorBalanceBack( $order );
                    self::affiliateBalanceback( $order );
                }

                self::quantityadded( $order );
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
        if ( !$pendingBalance ) {
            return false;
        }

        CancelOrderBalance::create( [
            // 'user_id' => $order->vendor_id,
            'balance' => $pendingBalance->amount,
        ] );

        return true;
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
        DB::beginTransaction();

        try {
            $order->update( ['status' => 'received', 'last_status' => 'received'] );

            if ( self::orderRequiresAffiliateCommissionPayment( $order ) ) {
                $vendor = function_exists( 'tenant' ) && tenant()
                    ? Tenant::on( 'mysql' )->find( tenant()->id )
                    : null;

                if ( ! $vendor ) {
                    DB::rollBack();

                    return response()->json( [
                        'status'  => 500,
                        'message' => 'Tenant not found.',
                    ], 500 );
                }

                $afiAmount = (float) ( $order->afi_amount ?? 0 );
                if ( $afiAmount > 0 ) {
                    if ( (float) $vendor->balance < $afiAmount ) {
                        DB::rollBack();

                        return response()->json( [
                            'status'  => 400,
                            'message' => 'Balance not available!',
                        ], 400 );
                    }

                    $vendor->decrement( 'balance', $afiAmount );

                    PaymentHistoryService::store(
                        uniqid(),
                        $afiAmount,
                        'My wallet',
                        'Affiliate commission',
                        '-',
                        '',
                        auth()->id()
                    );
                }
            }

            DB::commit();
        } catch ( \Exception $e ) {
            DB::rollBack();

            return response()->json( [
                'status'  => 400,
                'message' => $e->getMessage(),
            ], 400 );
        }

        if ( $order->wc_order_no != null ) {
            $wcOrderNo = explode( "-", $order->wc_order_no )[1];
            if ( $wcOrderNo ) {
                $data = self::wocommerceOrderStatusUpdate( $order->id, 'received', 'processing' );
            }
        }

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
        $courierOrder = OrderDeliveryToCourier::where( [
            'order_id'          => $order->id,
            'vendor_id'         => $order->vendor_id,
            'merchant_order_id' => $order->order_id,
        ] )->first();

        // Prefer courier credential selected on the order payload.
        $credential = null;
        if ( $courierOrder && !empty( $courierOrder->courier_id ) ) {
            $credential = CourierCredential::where( [
                'id'        => $courierOrder->courier_id,
                'vendor_id' => $order->vendor_id,
                'status'    => 'active',
            ] )->first();
        }

        // Fallback to vendor default active courier.
        if ( !$credential ) {
            $credential = CourierCredential::where( [
                'vendor_id' => $order->vendor_id,
                'status'    => 'active',
                'default'   => 'yes',
            ] )->first();
        }

        if ( !$credential ) {
            return self::markOrderAsProgress( $order );
        }

        if ( !$courierOrder ) {
            $courierOrder = self::createCourierOrderFromOrder( $order, $credential );
        } else {
            self::hydrateCourierOrderFromOrder( $courierOrder, $order, $credential );
        }

        if ( !$courierOrder->courier_id ) {
            $courierOrder->update( ['courier_id' => $credential->id] );
            $courierOrder->refresh();
        }

        if ( $credential->courier_name == 'pathao' ) {
            $missingFields = self::missingPathaoFields( $credential, $courierOrder );
            if ( !empty( $missingFields ) ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Pathao order data is incomplete.',
                    'missing' => $missingFields,
                ] );
            }

            $candidates = collect( [$credential] )->merge(
                CourierCredential::query()
                    // ->where( 'vendor_id', $order->vendor_id )
                    ->where( 'courier_name', 'pathao' )
                    ->where( 'status', 'active' )
                    ->where( 'id', '!=', $credential->id )
                    ->get()
            );

            $orderToCourier = null;
            $usedCredential = $credential;
            $triedStores    = [];

            foreach ( $candidates as $candidate ) {
                $candidateStoreId = is_numeric( $candidate->store_id ) ? (int) $candidate->store_id : null;
                if ( !$candidateStoreId || $candidateStoreId < 1 ) {
                    $triedStores[] = ['credential_id' => $candidate->id, 'store_id' => $candidate->store_id, 'result' => 'invalid_store_id'];
                    continue;
                }

                $access_token = PathaoService::getToken( $candidate->api_key, $candidate->secret_key, $candidate->client_email, $candidate->client_password );
                if ( !is_string( $access_token ) || $access_token === '' ) {
                    $triedStores[] = ['credential_id' => $candidate->id, 'store_id' => $candidateStoreId, 'result' => 'token_failed'];
                    $orderToCourier = $access_token;
                    continue;
                }

                $attempt = PathaoService::newOrder( $access_token, $candidateStoreId, $courierOrder );
                if ( self::isWrongStoreError( $attempt ) ) {
                    $freshAccessToken = PathaoService::getToken(
                        $candidate->api_key,
                        $candidate->secret_key,
                        $candidate->client_email,
                        $candidate->client_password,
                        true
                    );
                    if ( is_string( $freshAccessToken ) && $freshAccessToken !== '' ) {
                        $attempt = PathaoService::newOrder( $freshAccessToken, $candidateStoreId, $courierOrder );
                    }
                }

                $triedStores[] = ['credential_id' => $candidate->id, 'store_id' => $candidateStoreId, 'result' => isset( $attempt['data']['consignment_id'] ) ? 'success' : 'failed'];

                if ( is_array( $attempt ) && isset( $attempt['data']['consignment_id'] ) ) {
                    $orderToCourier = $attempt;
                    $usedCredential = $candidate;
                    break;
                }

                $orderToCourier = $attempt;
            }

            if ( !is_array( $orderToCourier ) || !isset( $orderToCourier['data']['consignment_id'] ) ) {
                return response()->json( [
                    'status'        => 400,
                    'message'       => self::courierErrorMessage( $orderToCourier, 'Courier order creation failed.' ),
                    'error_details' => self::courierErrorDetails( $orderToCourier ),
                    'pathao_debug'  => [
                        'credential_id' => $usedCredential->id ?? $credential->id,
                        'store_id'      => $usedCredential->store_id ?? $credential->store_id,
                        'client_email'  => $usedCredential->client_email ?? $credential->client_email ?? null,
                        'pathao_mode'   => env( 'PATHAO_MODE', 'live' ),
                        'courier_id'    => $courierOrder->courier_id,
                        'tried_stores'  => $triedStores,
                    ],
                ] );
            }

            if ( $courierOrder->courier_id != $usedCredential->id ) {
                $courierOrder->update( ['courier_id' => $usedCredential->id] );
                $courierOrder->refresh();
            }

            self::updateOrderProgressWithCourier( $order, $courierOrder, $orderToCourier['data']['consignment_id'], $usedCredential->courier_name );

            return response()->json( [
                'status'         => 200,
                'message'        => 'Order progress successfull!',
                'consignment_id' => $orderToCourier['data']['consignment_id'],
                'delivery_fee'   => $orderToCourier['data']['delivery_fee'] ?? null,
                'courier_name'   => $courierOrder->courierCredential->courier_name ?? $credential->courier_name,

            ] );
        } elseif ( $credential->courier_name == 'steadfast' ) {

            $orderToCourier = SteadFastService::order( $credential->api_key, $credential->secret_key, $courierOrder );
            if ( !is_array( $orderToCourier ) || !isset( $orderToCourier['status'], $orderToCourier['consignment'] ) || $orderToCourier['status'] !== 200 ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => self::courierErrorMessage( $orderToCourier, 'Courier order creation failed.' ),
                ] );
            }

            $consignment = $orderToCourier['consignment'];
            if ( !isset( $consignment['consignment_id'] ) ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Courier consignment ID not found.',
                ] );
            }

            self::updateOrderProgressWithCourier( $order, $courierOrder, $consignment['consignment_id'], $credential->courier_name );

            return response()->json( [
                'status'         => 200,
                'message'        => 'Order progress successful!',
                'consignment_id' => $consignment['consignment_id'],
                'delivery_fee'   => $consignment['cod_amount'] ?? null,
                'courier_name'   => $courierOrder->courierCredential->courier_name ?? $credential->courier_name,
            ] );
        } elseif ( $credential->courier_name == 'redx' ) {

            $orderToCourier = RedxService::newOrderRedx( $credential->api_key, $courierOrder );
            if ( !is_array( $orderToCourier ) || !isset( $orderToCourier['tracking_id'] ) ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => self::courierErrorMessage( $orderToCourier, 'Courier order creation failed.' ),
                ] );
            }

            self::updateOrderProgressWithCourier( $order, $courierOrder, $orderToCourier['tracking_id'], $credential->courier_name );

            return response()->json( [
                'status'         => 200,
                'message'        => 'Order progress successfull!',
                'consignment_id' => $orderToCourier['tracking_id'],
                'courier_name'   => $courierOrder->courierCredential->courier_name ?? $credential->courier_name,

            ] );
        }

        return response()->json( [
            'status'  => 400,
            'message' => 'Courier credential not found!',
        ] );

    }

    protected static function markOrderAsProgress( $order ) {
        $order->update( [
            'status'      => 'progress',
            'last_status' => 'progress',
        ] );

        self::syncWoocommerceStatus( $order, 'progress', 'processing' );

        return response()->json( [
            'status'  => 200,
            'message' => 'Order progress successfull!',
        ] );
    }

    protected static function updateOrderProgressWithCourier( $order, $courierOrder, $consignmentId, $fallbackCourierName = null ) {
        $courierName = $courierOrder->courierCredential->courier_name ?? $fallbackCourierName;

        $order->update( [
            'status'         => 'progress',
            'last_status'    => 'progress',
            'consignment_id' => $consignmentId,
            'courier_name'   => $courierName,
            'delivery_id'    => $courierName ? $consignmentId . "_+_" . $courierName : null,
        ] );

        self::syncWoocommerceStatus( $order, 'progress', 'processing' );
    }

    protected static function courierErrorMessage( $payload, $fallback ) {
        if ( is_array( $payload ) ) {
            if ( isset( $payload['details'] ) ) {
                if ( is_string( $payload['details'] ) && trim( $payload['details'] ) !== '' ) {
                    return $payload['details'];
                }

                if ( is_array( $payload['details'] ) ) {
                    if ( isset( $payload['details']['message'] ) && is_string( $payload['details']['message'] ) ) {
                        return $payload['details']['message'];
                    }

                    $encoded = json_encode( $payload['details'] );
                    if ( is_string( $encoded ) && $encoded !== '[]' && $encoded !== '{}' ) {
                        return $encoded;
                    }
                }
            }

            if ( isset( $payload['message'] ) && is_string( $payload['message'] ) ) {
                return $payload['message'];
            }

            if ( isset( $payload['error'] ) && is_string( $payload['error'] ) ) {
                return $payload['error'];
            }

            if ( isset( $payload['status'] ) ) {
                return $fallback . ' (status: ' . $payload['status'] . ')';
            }
        }

        return $fallback;
    }

    protected static function courierErrorDetails( $payload ) {
        if ( !is_array( $payload ) ) {
            return null;
        }

        if ( !isset( $payload['details'] ) ) {
            return null;
        }

        $details = $payload['details'];
        if ( is_string( $details ) ) {
            return ['raw' => $details];
        }

        if ( !is_array( $details ) ) {
            return ['raw' => json_encode( $details )];
        }

        $result = [];

        if ( isset( $details['message'] ) ) {
            $result['message'] = $details['message'];
        }

        if ( isset( $details['errors'] ) && is_array( $details['errors'] ) ) {
            $result['errors'] = $details['errors'];
        }

        // Sometimes Pathao sends validation hints under `data`
        if ( isset( $details['data'] ) && is_array( $details['data'] ) ) {
            $result['data'] = $details['data'];
        }

        if ( empty( $result ) ) {
            $result['raw'] = $details;
        }

        return $result;
    }

    protected static function isWrongStoreError( $payload ): bool {
        if ( !is_array( $payload ) ) {
            return false;
        }

        $haystack = '';
        if ( isset( $payload['message'] ) && is_string( $payload['message'] ) ) {
            $haystack .= ' ' . $payload['message'];
        }
        if ( isset( $payload['details'] ) ) {
            if ( is_string( $payload['details'] ) ) {
                $haystack .= ' ' . $payload['details'];
            } else {
                $haystack .= ' ' . json_encode( $payload['details'] );
            }
        }

        return stripos( $haystack, 'wrong store selected' ) !== false;
    }

    protected static function createCourierOrderFromOrder( $order, $credential ) {
        $payload = self::buildCourierPayloadFromOrder( $order, $credential );
        return OrderDeliveryToCourier::create( $payload );
    }

    protected static function hydrateCourierOrderFromOrder( $courierOrder, $order, $credential ): void {
        $payload = self::buildCourierPayloadFromOrder( $order, $credential );

        $fillable = [
            'courier_id',
            'merchant_order_id',
            'recipient_name',
            'recipient_phone',
            'recipient_address',
            'recipient_city',
            'recipient_zone',
            'recipient_area',
            'delivery_type',
            'item_type',
            'special_instruction',
            'item_quantity',
            'item_weight',
            'amount_to_collect',
            'item_description',
        ];

        $updates = [];
        foreach ( $fillable as $field ) {
            $current = $courierOrder->{$field};
            if ( empty( $current ) && $current !== 0 && $current !== '0' && isset( $payload[$field] ) ) {
                $updates[$field] = $payload[$field];
            }
        }

        if ( !empty( $updates ) ) {
            $courierOrder->update( $updates );
            $courierOrder->refresh();
        }
    }

    protected static function buildCourierPayloadFromOrder( $order, $credential ): array {
        $statusRequest = request();
        $dueAmount     = (int) round( (float) ( $order->due_amount ?? 0 ) );
        $collectAmount = $dueAmount > 0 ? $dueAmount : (int) round( (float) ( $order->product_amount ?? 0 ) );
        $locationGuess = self::guessPathaoLocationFromHistory( $order );
        $recipientCity = $statusRequest->input( 'recipient_city', $statusRequest->input( 'city_id', $locationGuess['recipient_city'] ?? 1 ) );
        $recipientZone = $statusRequest->input( 'recipient_zone', $statusRequest->input( 'zone_id', $locationGuess['recipient_zone'] ?? 1 ) );
        $recipientArea = $statusRequest->input( 'recipient_area', $statusRequest->input( 'area_id', $locationGuess['recipient_area'] ?? null ) );

        return [
            'order_id'            => $order->id,
            'vendor_id'           => $order->vendor_id,
            'affiliator_id'       => $order->affiliator_id,
            'courier_id'          => $credential->id,
            'merchant_order_id'   => $order->order_id,
            'recipient_name'      => $order->name,
            'recipient_phone'     => $order->phone,
            'recipient_address'   => $order->address,
            'recipient_city'      => $recipientCity,
            'recipient_zone'      => $recipientZone,
            'recipient_area'      => $recipientArea,
            'delivery_type'       => $statusRequest->input( 'delivery_type', 48 ),
            'item_type'           => $statusRequest->input( 'item_type', 2 ),
            'special_instruction' => $statusRequest->input( 'special_instruction', '' ),
            'item_quantity'       => $statusRequest->input( 'item_quantity', max( 1, (int) ( $order->qty ?? 1 ) ) ),
            'item_weight'         => $statusRequest->input( 'item_weight', 1 ),
            'amount_to_collect'   => $statusRequest->input( 'amount_to_collect', max( 0, $collectAmount ) ),
            'item_description'    => $statusRequest->input( 'item_description', 'Order #' . $order->order_id ),
        ];
    }

    protected static function guessPathaoLocationFromHistory( $order ): array {
        $history = null;

        if ( !empty( $order->phone ) ) {
            $history = OrderDeliveryToCourier::query()
                ->where( 'vendor_id', $order->vendor_id )
                ->where( 'recipient_phone', $order->phone )
                ->whereNotNull( 'recipient_city' )
                ->whereNotNull( 'recipient_zone' )
                ->latest( 'id' )
                ->first();
        }

        // Fallback: use last known location for this vendor
        if ( !$history ) {
            $history = OrderDeliveryToCourier::query()
                ->where( 'vendor_id', $order->vendor_id )
                ->whereNotNull( 'recipient_city' )
                ->whereNotNull( 'recipient_zone' )
                ->latest( 'id' )
                ->first();
        }

        if ( !$history ) {
            return [];
        }

        return [
            'recipient_city' => $history->recipient_city,
            'recipient_zone' => $history->recipient_zone,
            'recipient_area' => $history->recipient_area,
        ];
    }

    protected static function missingPathaoFields( $credential, $courierOrder ): array {
        $missing = [];

        if ( empty( $credential->store_id ) ) {
            $missing[] = 338136;
        }

        $requiredCourierFields = [
            'merchant_order_id',
            'recipient_name',
            'recipient_phone',
            'recipient_address',
            'delivery_type',
            'item_type',
            'item_quantity',
            'item_weight',
            'amount_to_collect',
            'item_description',
        ];

        foreach ( $requiredCourierFields as $field ) {
            if ( empty( $courierOrder->{$field} ) && $courierOrder->{$field} !== 0 && $courierOrder->{$field} !== '0' ) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    protected static function syncWoocommerceStatus( $order, $systemStatus, $wcStatus ) {
        if ( $order->wc_order_no == null ) {
            return;
        }

        $wcOrderNo = explode( '-', $order->wc_order_no )[1] ?? null;
        if ( !$wcOrderNo ) {
            return;
        }

        self::wocommerceOrderStatusUpdate( $order->id, $systemStatus, $wcStatus );
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
        $balance = self::orderPendingBalance( $order );
        if ( !$balance ) {
            return;
        }

        if ( $order->is_unlimited != 1 ) {
            $product      = Product::find( $order->product_id );
            $product->qty = ( $product->qty + $balance->qty );

            $variants = Order::normalizeVariants( $order->variants );
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

        $dropshipperMedia = $order->order_media === null
            || in_array( $order->order_media, ['dropshipper', 'Affiliator'], true );

        if ( $dropshipperMedia && self::orderHasDropshipperCommission( $order ) ) {
            $affiliateData = self::orderPendingBalance( $order );
            if ( !$affiliateData ) {
                return response()->json( [
                    'status'  => 500,
                    'message' => 'Pending balance data is missing for this tenant or order. Run tenant migrations and confirm the order created its pending balance record.',
                ], 500 );
            }

            if ( $affiliateData->status !== 'success' && !self::creditDropshipperCommission( $order, $affiliateData ) ) {
                return response()->json( [
                    'status'  => 500,
                    'message' => 'Unable to credit dropshipper commission. Confirm the order has a valid dropshipper tenant.',
                ], 500 );
            }
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
        if ( !self::pendingBalanceTableExists() ) {
            return null;
        }

        $pendingBalance = PendingBalance::where( 'order_id', $order->id )->first();
        if ( $pendingBalance ) {
            return $pendingBalance;
        }

        if ( !self::orderHasDropshipperCommission( $order ) ) {
            return null;
        }

        return PendingBalance::create( [
            'affiliator_id' => $order->affiliator_id,
            'product_id'    => $order->product_id,
            'order_id'      => $order->id,
            'qty'           => $order->qty ?? 0,
            'amount'        => $order->afi_amount,
            'status'        => 'pending',
        ] );
    }

    protected static function orderHasDropshipperCommission( Order $order ): bool {
        return (bool) $order->tenant_id
            && (int) $order->affiliator_id > 0
            && $order->afi_amount !== null
            && (float) $order->afi_amount > 0;
    }

    protected static function orderRequiresAffiliateCommissionPayment( Order $order ): bool {
        if ( (int) ( $order->custom_order ?? 0 ) !== 0 ) {
            return false;
        }

        if ( (int) ( $order->affiliator_id ?? 0 ) <= 0 ) {
            return false;
        }

        if ( (float) ( $order->afi_amount ?? 0 ) <= 0 ) {
            return false;
        }

        return ! self::isDirectWebsiteOrderMedia( $order );
    }

    protected static function isDirectWebsiteOrderMedia( Order $order ): bool {
        return in_array(
            (string) ( $order->order_media ?? '' ),
            ['website', 'website-guest', 'Direct'],
            true
        );
    }

    protected static function isDropshipperOrderMedia( Order $order ): bool {
        $media = (string) ( $order->order_media ?? '' );

        return $media === '' || $media === 'null' || in_array( $media, ['dropshipper', 'Affiliator'], true );
    }

    protected static function creditDropshipperCommission( Order $order, PendingBalance $affiliateData ): bool {
        $amount = (float) $affiliateData->amount;
        if ( $amount <= 0 ) {
            $affiliateData->update( ['status' => 'success'] );

            return true;
        }

        $dropshipperTenant = Tenant::on( 'mysql' )->find( $order->tenant_id );
        if ( !$dropshipperTenant ) {
            return false;
        }

        DB::connection( 'mysql' )->transaction( function () use ( $dropshipperTenant, $amount, $order, $affiliateData ) {
            $dropshipperTenant->increment( 'balance', $amount );

            PaymentHistoryService::store(
                uniqid(),
                $amount,
                'My wallet',
                'Product commission',
                '+',
                '',
                $dropshipperTenant->id,
                [
                    'entity_type' => 'tenant',
                    'tenant_id'   => $dropshipperTenant->id,
                    'user_id'     => $order->affiliator_id,
                ]
            );

            $affiliateData->update( ['status' => 'success'] );
        } );

        return true;
    }

    protected static function pendingBalanceTableExists() {
        return Schema::hasTable( 'pending_balances' );
    }

    static function response( $message ) {
        return response()->json( [
            'status'  => 200,
            'message' => $message,
        ] );
    }

    protected static function wocommerceOrderStatusUpdate( $orderId, $systemStatus, $wcStatus ) {
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

            $credentials = WoocommerceCredential::where( 'vendor_id', $order->vendor_id )->first();
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
