<?php

namespace App\Services;

use App\Enums\Status;
use App\Helper\RedirectHelper;
use App\Models\Cart;
use App\Models\DeliveryCharge;
use App\Models\Order;
use App\Models\OrderDetails;
use App\Models\PaymentGatewaySetting;
use App\Models\PaymentStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\TenantCoupon;
use App\Service\Vendor\ProductVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Page-builder checkout: fixed cart/delivery/totals + dynamic form_data JSON.
 */
class StorefrontCheckoutService
{
    public static function isModernPayload( Request $request ): bool
    {
        return $request->has( 'items' )
            && is_array( $request->input( 'items' ) )
            && $request->has( 'form_data' )
            && $request->has( 'delivery' );
    }

    public static function place( Request $request, bool $isGuest = false ): JsonResponse
    {
        $errors = self::validatePayload( $request );
        if ( $errors !== [] ) {
            return response()->json( [
                'success' => false,
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => $errors,
            ], 422 );
        }

        $paymentType = self::normalizePaymentType( $request->input( 'payment_type', 'COD' ) );
        if ( ! in_array( $paymentType, ['COD', 'EPS', 'UDDOKTAPAY', 'MY-WALLET'], true ) ) {
            return response()->json( [
                'success' => false,
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => ['payment_type' => ['Unsupported payment type. Use COD, EPS, or uddoktapay.']],
            ], 422 );
        }

        $prepared = self::prepareCheckoutContext( $request, $isGuest );
        if ( $prepared instanceof JsonResponse ) {
            return $prepared;
        }

        // Online gateways: validate + reserve PaymentStore, redirect — order is created after success.
        if ( in_array( $paymentType, ['EPS', 'UDDOKTAPAY'], true ) ) {
            return self::initiateGatewayCheckout( $prepared, $paymentType, $isGuest );
        }

        return self::commitCheckoutOrders( $prepared, $paymentType, $isGuest, paidOnline: false );
    }

    /**
     * Complete a modern page-builder checkout after EPS / UddoktaPay success.
     *
     * @return array{orders: array<int, array<string, mixed>>, total: float}
     */
    public static function completeFromPaymentStore( PaymentStore $payment ): array
    {
        $info = is_array( $payment->info ) ? $payment->info : [];
        if ( empty( $info['modern_checkout'] ) || ! is_array( $info['checkout'] ?? null ) ) {
            throw new \RuntimeException( 'Modern checkout payload missing on payment.' );
        }

        $checkout    = $info['checkout'];
        $paymentType = self::normalizePaymentType(
            $info['payment_gateway']
                ?? $payment->payment_gateway
                ?? ( $checkout['payment_type'] ?? 'COD' )
        );
        if ( ! in_array( $paymentType, ['EPS', 'UDDOKTAPAY'], true ) ) {
            $paymentType = $payment->payment_gateway === 'uddoktapay' ? 'UDDOKTAPAY' : 'EPS';
        }

        $isGuest = (bool) ( $checkout['is_guest'] ?? true );
        $userId  = (int) ( $checkout['user_id'] ?? $info['userid'] ?? 0 );

        $fakeRequest = Request::create( '/', 'POST', [
            'payment_type' => strtolower( $paymentType ),
            'tenant_id'    => $checkout['merchant_tenant_id'] ?? $info['tenant_id'] ?? null,
            'delivery'     => $checkout['delivery'] ?? [],
            'totals'       => $checkout['totals'] ?? [],
            'coupon'       => $checkout['coupon'] ?? [],
            'items'        => $checkout['items'] ?? [],
            'customer'     => $checkout['customer'] ?? [],
            'form_data'    => $checkout['form_data'] ?? ['schema' => [], 'values' => []],
        ] );

        // Re-resolve lines/stock at payment time (prices from trusted stored lines when carts gone).
        $prepared = self::prepareCheckoutContext(
            $fakeRequest,
            $isGuest,
            $userId > 0 ? $userId : null,
            is_array( $checkout['trusted_lines'] ?? null ) ? $checkout['trusted_lines'] : null
        );
        if ( $prepared instanceof JsonResponse ) {
            $payload = $prepared->getData( true );
            throw new \RuntimeException( (string) ( $payload['message'] ?? 'Checkout validation failed after payment.' ) );
        }

        $response = self::commitCheckoutOrders( $prepared, $paymentType, $isGuest, paidOnline: true, userId: $userId );
        $payload  = $response->getData( true );
        if ( ( $payload['status'] ?? null ) !== 200 ) {
            throw new \RuntimeException( (string) ( $payload['message'] ?? 'Order placement failed after payment.' ) );
        }

        return [
            'orders' => $payload['data']['orders'] ?? [],
            'total'  => (float) ( $payload['data']['totals']['total'] ?? 0 ),
        ];
    }

    public static function normalizePaymentType( mixed $paymentType ): string
    {
        $type = strtolower( trim( (string) $paymentType ) );

        return match ( $type ) {
            'eps', 'aamarpay' => 'EPS',
            'uddoktapay', 'uddokta' => 'UDDOKTAPAY',
            'my-wallet', 'my_wallet', 'wallet' => 'MY-WALLET',
            'cod', '' => 'COD',
            default => strtoupper( $type ),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $trustedLines
     * @return array<string, mixed>|JsonResponse
     */
    private static function prepareCheckoutContext(
        Request $request,
        bool $isGuest,
        ?int $userId = null,
        ?array $trustedLines = null
    ): array|JsonResponse {
        $customer = self::mergeCustomerFromForm(
            (array) $request->input( 'customer', [] ),
            (array) $request->input( 'form_data', [] )
        );
        $formData = (array) $request->input( 'form_data', [] );
        $delivery = self::resolveDelivery( (array) $request->input( 'delivery', [] ) );
        if ( isset( $delivery['error'] ) ) {
            return response()->json( [
                'success' => false,
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => ['delivery.id' => [$delivery['error']]],
            ], 422 );
        }

        if ( is_array( $trustedLines ) && $trustedLines !== [] ) {
            $lines   = [];
            $cartIds = [];
            $errors  = [];

            foreach ( array_values( $trustedLines ) as $index => $line ) {
                $line      = (array) $line;
                $productId = (int) ( $line['product_id'] ?? 0 );
                $qty       = max( 1, (int) ( $line['qty'] ?? 1 ) );
                $variantId = ProductVariantService::normalizeNullableId( $line['variant_id'] ?? null );
                $merchantTenantId = $request->input( 'tenant_id' )
                    ?: ( function_exists( 'tenant' ) && tenant() ? tenant()->id : null );

                $product = $merchantTenantId
                    ? CrossTenantQueryService::getSingleRecordFromTenant(
                        $merchantTenantId,
                        Product::class,
                        fn ( $query ) => $query->where( 'id', $productId )->where( 'status', 'active' )
                    )
                    : Product::where( 'id', $productId )->where( 'status', 'active' )->first();

                if ( ! $product ) {
                    $errors["items.$index.product_id"] = ['Product is not available.'];
                    continue;
                }

                $stockError = self::assertStock( $product, $variantId, $qty, $merchantTenantId );
                if ( $stockError ) {
                    $errors["items.$index.qty"] = [$stockError];
                    continue;
                }

                if ( ! empty( $line['cart_id'] ) ) {
                    $cartIds[] = (int) $line['cart_id'];
                }
                $lines[] = $line;
            }

            if ( $errors !== [] ) {
                return response()->json( [
                    'success' => false,
                    'status'  => 422,
                    'message' => 'Validation failed',
                    'errors'  => $errors,
                ], 422 );
            }

            $cartIds = array_values( array_unique( $cartIds ) );
        } else {
            $authUserId = $userId ?? ( $isGuest ? 0 : (int) auth()->id() );
            $resolvedLines = $isGuest
                ? self::resolveGuestLines( (array) $request->input( 'items', [] ) )
                : self::resolveAuthLines( (array) $request->input( 'items', [] ), $authUserId );

            if ( isset( $resolvedLines['error'] ) ) {
                return response()->json( [
                    'success' => false,
                    'status'  => 422,
                    'message' => 'Validation failed',
                    'errors'  => $resolvedLines['errors'] ?? ['items' => [$resolvedLines['error']]],
                ], 422 );
            }

            $lines   = $resolvedLines['lines'];
            $cartIds = $resolvedLines['cart_ids'] ?? [];
        }

        $subtotal = round( collect( $lines )->sum( 'line_total' ), 2 );
        $shipping = (float) $delivery['charge'];

        $couponInput = (array) $request->input( 'coupon', [] );
        $couponCode  = $couponInput['code'] ?? $request->input( 'coupon_code' );
        $couponCtx   = self::resolveCoupon(
            is_string( $couponCode ) ? $couponCode : null,
            $subtotal,
            $isGuest ? 0 : (int) ( $userId ?? auth()->id() ),
            $customer['email'] ?? ( $formData['values']['email'] ?? null )
        );
        if ( isset( $couponCtx['error'] ) ) {
            return response()->json( [
                'success' => false,
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => ['coupon.code' => [$couponCtx['error']]],
            ], 422 );
        }

        $discount = (float) $couponCtx['discount'];
        $total    = max( 0, round( $subtotal + $shipping - $discount, 2 ) );

        $merchantTenantId = self::resolveMerchantTenantId( $request, $lines );
        $tenant           = Tenant::on( 'mysql' )->find( $merchantTenantId );
        if ( ! $tenant ) {
            return response()->json( [
                'success' => false,
                'status'  => 400,
                'message' => 'Tenant not found',
            ], 400 );
        }

        return [
            'customer'           => $customer,
            'form_data'          => $formData,
            'delivery'           => $delivery,
            'lines'              => $lines,
            'cart_ids'           => $cartIds,
            'subtotal'           => $subtotal,
            'shipping'           => $shipping,
            'discount'           => $discount,
            'total'              => $total,
            'coupon_ctx'         => $couponCtx,
            'merchant_tenant_id' => $merchantTenantId,
            'tenant'             => $tenant,
            'connection_name'    => CrossTenantQueryService::connectionForTenant( $tenant ),
            'user_id'            => $isGuest ? null : (int) ( $userId ?? auth()->id() ),
        ];
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private static function commitCheckoutOrders(
        array $prepared,
        string $paymentType,
        bool $isGuest,
        bool $paidOnline = false,
        ?int $userId = null
    ): JsonResponse {
        $connectionName   = $prepared['connection_name'];
        $lines            = $prepared['lines'];
        $customer         = $prepared['customer'];
        $formData         = $prepared['form_data'];
        $delivery         = $prepared['delivery'];
        $subtotal         = $prepared['subtotal'];
        $shipping         = $prepared['shipping'];
        $discount         = $prepared['discount'];
        $total            = $prepared['total'];
        $couponCtx        = $prepared['coupon_ctx'];
        $merchantTenantId = $prepared['merchant_tenant_id'];
        $cartIds          = $prepared['cart_ids'] ?? [];
        $resolvedUserId   = $userId ?? $prepared['user_id'] ?? ( $isGuest ? null : auth()->id() );

        try {
            $orderResult = DB::connection( $connectionName )->transaction( function () use (
                $connectionName,
                $lines,
                $customer,
                $formData,
                $delivery,
                $subtotal,
                $shipping,
                $discount,
                $total,
                $paymentType,
                $couponCtx,
                $isGuest,
                $merchantTenantId,
                $paidOnline,
                $resolvedUserId
            ) {
                $groups          = collect( $lines )->groupBy( 'product_id' );
                $savedOrders     = [];
                $couponLeft      = $discount;
                $shippingLeft    = $shipping;
                $deliveryApplied = false;
                $couponApplied   = false;

                foreach ( $groups as $productId => $groupLines ) {
                    $groupLines = $groupLines->values()->all();
                    $product    = Product::on( $connectionName )->where( 'id', $productId )->where( 'status', 'active' )->first();

                    if ( ! $product ) {
                        throw new \RuntimeException( 'Product #' . $productId . ' is not available.' );
                    }

                    $groupSubtotal = round( collect( $groupLines )->sum( 'line_total' ), 2 );
                    $groupQty      = (int) collect( $groupLines )->sum( 'qty' );
                    $groupShipping = ( ! $deliveryApplied ) ? $shippingLeft : 0.0;
                    $groupDiscount = 0.0;

                    if ( ! $couponApplied && $couponLeft > 0 ) {
                        $groupDiscount = min( $couponLeft, max( 0, $groupSubtotal + $groupShipping ) );
                    }

                    $groupDue  = max( 0, round( $groupSubtotal + $groupShipping - $groupDiscount, 2 ) );
                    $groupPaid = $paidOnline ? $groupDue : 0.0;

                    $orderId = self::generateUniqueOrderId( $connectionName );
                    $order   = new Order();
                    $order->setConnection( $connectionName );
                    $order->order_id        = $orderId;
                    $order->user_id         = $isGuest ? null : $resolvedUserId;
                    $order->vendor_id       = $product->user_id;
                    $order->affiliator_id   = 0;
                    $order->product_id      = $product->id;
                    $order->name            = $customer['name'] ?? ( $formData['values']['name'] ?? null );
                    $order->phone           = $customer['phone'] ?? ( $formData['values']['phone'] ?? null );
                    $order->email           = self::nullableEmail(
                        $customer['email'] ?? ( $formData['values']['email'] ?? null )
                    );
                    $order->address         = $customer['address'] ?? ( $formData['values']['address'] ?? null );
                    $order->additional_note = $customer['notes'] ?? ( $formData['values']['notes'] ?? null );
                    $order->city            = self::nullableString(
                        $customer['city'] ?? ( $formData['values']['city'] ?? null )
                    );
                    $order->variants        = array_map( fn ( $line ) => [
                        'variant_id'     => $line['variant_id'],
                        'id'             => $line['variant_id'],
                        'label'          => $line['variant_label'],
                        'qty'            => $line['qty'],
                        'rate'           => $line['unit_price'],
                        'price'          => $line['unit_price'],
                        'sku'            => $line['sku'],
                        'image'          => $line['image'],
                        'options'        => $line['variant_options'],
                        'color'          => $line['color'],
                        'size'           => $line['size'],
                        'unit_id'        => $line['unit_id'],
                        'product_name'   => $line['product_name'],
                        'line_key'       => $line['line_key'] ?? null,
                        'cart_id'        => $line['cart_id'] ?? null,
                        'cart_detail_id' => $line['cart_detail_id'] ?? null,
                    ], $groupLines );
                    $order->afi_amount      = 0;
                    $order->product_amount  = $groupSubtotal;
                    $order->due_amount      = $paidOnline ? 0 : $groupDue;
                    $order->paid_amount     = $groupPaid;
                    $order->status          = Status::Pending->value;
                    $order->category_id     = $product->category_id;
                    $order->qty             = $groupQty;
                    $order->delivery_charge = $groupShipping;
                    $order->delivery_area   = $delivery['area'] ?? null;
                    $order->delivery_id     = $delivery['id'] ?? null;
                    $order->sale_discount   = $groupDiscount > 0 ? $groupDiscount : null;
                    $order->coupon_code     = $groupDiscount > 0 ? ( $couponCtx['coupon']->code ?? null ) : null;
                    $order->order_media     = $isGuest ? 'website-guest' : 'website';
                    $order->tenant_id       = function_exists( 'tenant' ) && tenant() ? tenant()->id : $merchantTenantId;

                    if ( Schema::connection( $connectionName )->hasColumn( 'orders', 'form_data' ) ) {
                        $order->form_data = $formData;
                    }
                    if ( Schema::connection( $connectionName )->hasColumn( 'orders', 'payment_type' ) ) {
                        $order->payment_type = $paymentType;
                    }

                    $order->save();

                    foreach ( $groupLines as $line ) {
                        self::createOrderDetail( $connectionName, $order, $line );
                        self::decrementLineStock( $connectionName, $product, $line );
                    }

                    if ( $groupDiscount > 0 && ( $couponCtx['coupon'] ?? null ) instanceof TenantCoupon ) {
                        try {
                            TenantCouponService::recordUsage(
                                $couponCtx['coupon'],
                                $groupDiscount,
                                (int) $order->id,
                                $isGuest ? null : (int) $resolvedUserId,
                                $order->email
                            );
                        } catch ( \Throwable $e ) {
                            // Order already saved; coupon usage is best-effort.
                        }
                        $couponLeft    = max( 0, $couponLeft - $groupDiscount );
                        $couponApplied = true;
                    }

                    if ( $groupShipping > 0 ) {
                        $shippingLeft    = 0;
                        $deliveryApplied = true;
                    }

                    $savedOrders[] = [
                        'id'           => (int) $order->id,
                        'order_id'     => (string) $order->order_id,
                        'order_number' => (string) $order->order_id,
                    ];
                }

                return [
                    'orders'   => $savedOrders,
                    'total'    => $total,
                    'subtotal' => $subtotal,
                    'shipping' => $shipping,
                    'discount' => $discount,
                ];
            } );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'success' => false,
                'status'  => 500,
                'message' => 'Order placement failed: ' . $e->getMessage(),
            ], 500 );
        }

        if ( ! $isGuest && $cartIds !== [] && $resolvedUserId ) {
            Cart::where( 'user_id', $resolvedUserId )
                ->whereIn( 'id', $cartIds )
                ->get()
                ->each
                ->delete();
        }

        $primary = $orderResult['orders'][0] ?? null;

        return response()->json( [
            'status'        => 200,
            'success'       => true,
            'message'       => 'Order placed successfully',
            'order_id'      => $primary['order_id'] ?? null,
            'order_ids'     => array_column( $orderResult['orders'], 'order_id' ),
            'orders_placed' => count( $orderResult['orders'] ),
            'data'          => [
                'order_id'     => $primary['id'] ?? null,
                'order_number' => $primary['order_number'] ?? null,
                'orders'       => $orderResult['orders'],
                'totals'       => [
                    'subtotal' => $orderResult['subtotal'],
                    'shipping' => $orderResult['shipping'],
                    'discount' => $orderResult['discount'],
                    'total'    => $orderResult['total'],
                ],
            ],
        ] );
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private static function initiateGatewayCheckout( array $prepared, string $paymentType, bool $isGuest ): JsonResponse
    {
        $total = (float) $prepared['total'];
        if ( $total <= 0 ) {
            return response()->json( [
                'success' => false,
                'status'  => 422,
                'message' => 'Payable amount must be greater than zero for online payment.',
            ], 422 );
        }

        $storefrontTenantId = function_exists( 'tenant' ) && tenant()
            ? (string) tenant()->id
            : (string) $prepared['merchant_tenant_id'];

        if ( $storefrontTenantId === '' ) {
            return response()->json( [
                'success' => false,
                'status'  => 400,
                'message' => 'Tenant context is required for gateway checkout.',
            ], 400 );
        }

        $prefix = $paymentType === 'UDDOKTAPAY' ? 'udp' : 'eps';
        $trx    = preg_replace( '/[^A-Za-z0-9]/', '', uniqid( $prefix, true ) ) ?: uniqid();
        $first  = $prepared['lines'][0] ?? [];
        $coupon = $prepared['coupon_ctx']['coupon'] ?? null;
        $customer = $prepared['customer'];

        $checkoutSnapshot = [
            'is_guest'           => $isGuest,
            'user_id'            => $prepared['user_id'],
            'merchant_tenant_id' => $prepared['merchant_tenant_id'],
            'payment_type'       => strtolower( $paymentType ),
            'delivery'           => [
                'id'     => $prepared['delivery']['id'] ?? null,
                'area'   => $prepared['delivery']['area'] ?? null,
                'charge' => $prepared['delivery']['charge'] ?? 0,
            ],
            'totals'             => [
                'subtotal' => $prepared['subtotal'],
                'shipping' => $prepared['shipping'],
                'discount' => $prepared['discount'],
                'total'    => $prepared['total'],
            ],
            'coupon'             => [
                'code'     => $coupon?->code,
                'discount' => $prepared['discount'],
            ],
            'items'              => $prepared['lines'],
            'trusted_lines'      => $prepared['lines'],
            'customer'           => $customer,
            'form_data'          => $prepared['form_data'],
        ];

        $info = RedirectHelper::appendPaymentReturnUrl( [
            'modern_checkout'      => true,
            'checkout'             => $checkoutSnapshot,
            'cartid'               => $first['cart_id'] ?? null,
            'productid'            => $first['product_id'] ?? null,
            'totalqty'             => $first['qty'] ?? 1,
            'userid'               => $prepared['user_id'] ?? 0,
            'tenant_id'            => $prepared['merchant_tenant_id'],
            'storefront_tenant_id' => $storefrontTenantId,
            'placing_tenant_id'    => $storefrontTenantId,
            'order_media'          => $isGuest ? 'website-guest' : 'website',
            'coupon_id'            => $coupon?->id,
            'coupon_discount'      => (float) $prepared['discount'],
            'customer'             => [
                'name'  => $customer['name'] ?? null,
                'email' => $customer['email'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ],
            // Keep empty legacy checkouts so completion routes to modern path only.
            'checkouts'            => [],
        ] );

        try {
            if ( $paymentType === 'UDDOKTAPAY' ) {
                TenantPaymentGatewayService::applyForProductCheckout(
                    PaymentGatewaySetting::GATEWAY_UDDOKTAPAY,
                    $storefrontTenantId
                );

                $successUrl = UddoktaPayService::tenantCallbackUrl( $storefrontTenantId, 'product-checkout-success' );
                $cancelUrl  = UddoktaPayService::tenantCallbackUrl( $storefrontTenantId, 'product-checkout-cancel' );
                $webhookUrl = UddoktaPayService::tenantCallbackUrl( $storefrontTenantId, 'product-checkout-webhook' );

                $gateway = UddoktaPayService::gateway(
                    $total,
                    $trx,
                    [
                        'name'  => $customer['name'] ?? null,
                        'email' => $customer['email'] ?? null,
                        'phone' => $customer['phone'] ?? null,
                    ],
                    $successUrl,
                    $cancelUrl,
                    $webhookUrl,
                    ['tenant_id' => $storefrontTenantId]
                );

                $info['invoice_id'] = $gateway->invoice_id ?? null;

                PaymentStore::on( 'mysql' )->create( [
                    'payment_gateway' => 'uddoktapay',
                    'trxid'           => $trx,
                    'status'          => 'pending',
                    'last_status'     => 'pending',
                    'order_media'     => $isGuest ? 'website-guest' : 'website',
                    'payment_type'    => 'checkout',
                    'info'            => $info,
                ] );
            } else {
                PaymentStore::on( 'mysql' )->create( [
                    'payment_gateway' => 'aamarpay',
                    'trxid'           => $trx,
                    'status'          => 'pending',
                    'last_status'     => 'pending',
                    'order_media'     => $isGuest ? 'website-guest' : 'website',
                    'payment_type'    => 'checkout',
                    'info'            => $info,
                ] );

                TenantPaymentGatewayService::applyForProductCheckout(
                    PaymentGatewaySetting::GATEWAY_EPS,
                    $storefrontTenantId
                );

                $successUrl = EpsPaymentService::paymentSuccessUrl( 'product-checkout-success' );
                $gateway    = AamarPayService::gateway(
                    $total,
                    $trx,
                    'Product Checkout',
                    $successUrl,
                    'tenant',
                    [
                        'name'  => $customer['name'] ?? null,
                        'email' => $customer['email'] ?? null,
                        'phone' => $customer['phone'] ?? null,
                    ]
                );
            }
        } catch ( \Throwable $e ) {
            return response()->json( [
                'success' => false,
                'status'  => 400,
                'message' => $e->getMessage(),
            ], 400 );
        } finally {
            if ( $paymentType === 'UDDOKTAPAY' ) {
                UddoktaPayService::clearRuntimeCredentials();
            } else {
                EpsConfig::clearRuntimeCredentials();
                EpsPaymentService::clearTokenCache();
            }
        }

        return response()->json( [
            'status'       => 200,
            'success'      => true,
            'result'       => $gateway->result ?? 'true',
            'payment_url'  => $gateway->payment_url ?? null,
            'payment_type' => strtolower( $paymentType ),
            'trxid'        => $trx,
            'amount'       => $total,
            'message'      => $paymentType === 'UDDOKTAPAY'
                ? 'Redirect to UddoktaPay payment gateway.'
                : 'Redirect to EPS payment gateway.',
            'data'         => [
                'totals' => [
                    'subtotal' => $prepared['subtotal'],
                    'shipping' => $prepared['shipping'],
                    'discount' => $prepared['discount'],
                    'total'    => $prepared['total'],
                ],
            ],
        ] );
    }

    /**
     * Prefer explicit customer.* then form_data.values.* for core fields.
     *
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    private static function mergeCustomerFromForm( array $customer, array $formData ): array
    {
        $values = (array) ( $formData['values'] ?? [] );
        foreach ( ['name', 'phone', 'email', 'address', 'notes', 'city'] as $key ) {
            $current = $customer[$key] ?? null;
            if ( ( $current === null || trim( (string) $current ) === '' ) && isset( $values[$key] ) ) {
                $customer[$key] = $values[$key];
            }
        }

        return $customer;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function validatePayload( Request $request ): array
    {
        $validator = Validator::make( $request->all(), [
            'payment_type'              => ['required', 'string'],
            'delivery'                  => ['required', 'array'],
            'delivery.id'               => ['required'],
            'totals'                    => ['nullable', 'array'],
            'coupon'                    => ['nullable', 'array'],
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.product_id'        => ['required', 'integer', 'min:1'],
            'items.*.qty'               => ['required', 'numeric', 'min:1'],
            'items.*.unit_price'        => ['nullable', 'numeric', 'min:0'],
            'items.*.variant_id'        => ['nullable'],
            'customer'                  => ['required', 'array'],
            'form_data'                 => ['required', 'array'],
            'form_data.schema'          => ['required', 'array'],
            'form_data.values'          => ['required', 'array'],
        ] );

        if ( $validator->fails() ) {
            return $validator->errors()->toArray();
        }

        $errors  = [];
        $schema  = (array) $request->input( 'form_data.schema', [] );
        $values  = (array) $request->input( 'form_data.values', [] );

        foreach ( $schema as $field ) {
            $field = (array) $field;
            $id    = (string) ( $field['id'] ?? '' );
            if ( $id === '' ) {
                continue;
            }

            $required = filter_var( $field['required'] ?? false, FILTER_VALIDATE_BOOLEAN );
            $value    = $values[$id] ?? null;
            $label    = $field['label'] ?? $id;

            if ( $required && ( $value === null || trim( (string) $value ) === '' ) ) {
                $errors["form_data.values.$id"] = ["{$label} is required"];
                continue;
            }

            if ( $value === null || $value === '' ) {
                continue;
            }

            $type = $field['type'] ?? 'text';
            if ( $type === 'email' && trim( (string) $value ) !== '' && ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
                $errors["form_data.values.$id"] = ["{$label} must be a valid email"];
            }
            if ( $type === 'phone' && strlen( preg_replace( '/\D+/', '', (string) $value ) ?? '' ) < 10 ) {
                $errors["form_data.values.$id"] = ["{$label} must be a valid phone number"];
            }
            if ( $type === 'number' && ! is_numeric( $value ) ) {
                $errors["form_data.values.$id"] = ["{$label} must be a number"];
            }
        }

        // Ensure convenience customer fields exist when schema requires them.
        foreach ( ['name', 'phone', 'address'] as $key ) {
            $customerVal = $request->input( "customer.$key" );
            $formVal     = $values[$key] ?? null;
            if ( ( $customerVal === null || trim( (string) $customerVal ) === '' )
                && ( $formVal === null || trim( (string) $formVal ) === '' ) ) {
                // Only enforce if schema marks required or customer block is empty for core fields.
                $schemaField = collect( $schema )->firstWhere( 'id', $key );
                if ( $schemaField && filter_var( $schemaField['required'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
                    continue; // already handled above
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @return array<string, mixed>
     */
    private static function resolveDelivery( array $delivery ): array
    {
        $id = (int) ( $delivery['id'] ?? 0 );
        if ( $id <= 0 ) {
            return ['error' => 'Invalid delivery area'];
        }

        $row = DeliveryCharge::query()
            ->where( 'id', $id )
            ->where( 'status', 'active' )
            ->first();

        if ( ! $row ) {
            return ['error' => 'Invalid delivery area'];
        }

        return [
            'id'     => (int) $row->id,
            'area'   => $row->area,
            'charge' => (float) $row->charge,
        ];
    }

    /**
     * @return array{coupon: ?TenantCoupon, discount: float}|array{error: string}
     */
    private static function resolveCoupon( ?string $code, float $subtotal, int $userId, mixed $email ): array
    {
        $code = trim( (string) $code );
        if ( $code === '' || strtolower( $code ) === 'null' ) {
            return ['coupon' => null, 'discount' => 0.0];
        }

        $result = TenantCouponService::validateForCheckout(
            $code,
            $subtotal,
            $userId > 0 ? $userId : null,
            is_string( $email ) ? $email : null
        );

        if ( isset( $result['error'] ) ) {
            return ['error' => $result['error']];
        }

        return [
            'coupon'   => $result['coupon'],
            'discount' => (float) $result['discount_amount'],
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array{lines: array<int, array<string, mixed>>, cart_ids: array<int>}|array{error: string, errors?: array}
     */
    private static function resolveAuthLines( array $items, int $userId ): array
    {
        $lines   = [];
        $cartIds = [];
        $errors  = [];

        foreach ( array_values( $items ) as $index => $item ) {
            $item          = (array) $item;
            $cartId        = (int) ( $item['cart_id'] ?? 0 );
            $cartDetailId  = (int) ( $item['cart_detail_id'] ?? 0 );
            $productId     = (int) ( $item['product_id'] ?? 0 );
            $qty           = max( 1, (int) ( $item['qty'] ?? 1 ) );
            $variantId     = ProductVariantService::normalizeNullableId( $item['variant_id'] ?? null );

            $cart = Cart::where( 'user_id', $userId )
                ->with( 'cartDetails' )
                ->find( $cartId );

            if ( ! $cart ) {
                $errors["items.$index.cart_id"] = ['Cart line not found.'];
                continue;
            }

            $detail = $cartDetailId > 0
                ? $cart->cartDetails->firstWhere( 'id', $cartDetailId )
                : $cart->cartDetails->first();

            if ( ! $detail ) {
                $errors["items.$index.cart_detail_id"] = ['Cart detail not found.'];
                continue;
            }

            if ( $productId > 0 && (int) $cart->product_id !== $productId ) {
                $errors["items.$index.product_id"] = ['Product does not match cart.'];
                continue;
            }

            $productId        = (int) $cart->product_id;
            $merchantTenantId = $cart->tenant_id ?: ( function_exists( 'tenant' ) && tenant() ? tenant()->id : null );
            $product          = $merchantTenantId
                ? CrossTenantQueryService::getSingleRecordFromTenant(
                    $merchantTenantId,
                    Product::class,
                    fn ( $query ) => $query->where( 'id', $productId )->where( 'status', 'active' )
                )
                : Product::where( 'id', $productId )->where( 'status', 'active' )->first();

            if ( ! $product ) {
                $errors["items.$index.product_id"] = ['Product is not available.'];
                continue;
            }

            $detailVariantId = ProductVariantService::normalizeNullableId( $detail->variant_id );
            if ( $variantId && $detailVariantId && $variantId !== $detailVariantId ) {
                $errors["items.$index.variant_id"] = ['Variant does not match cart detail.'];
                continue;
            }
            $variantId = $variantId ?: $detailVariantId;

            $unitPrice = self::resolveTrustedUnitPrice( $product, $variantId, $cart, $item, $merchantTenantId );
            $stockError = self::assertStock( $product, $variantId, $qty, $merchantTenantId );
            if ( $stockError ) {
                $errors["items.$index.qty"] = [$stockError];
                continue;
            }

            $cartIds[] = $cart->id;
            $lines[]   = self::buildLinePayload( $item, $product, $variantId, $qty, $unitPrice, $cart->id, $detail->id );
        }

        if ( $errors !== [] ) {
            return ['error' => 'Invalid cart items', 'errors' => $errors];
        }

        return [
            'lines'    => $lines,
            'cart_ids' => array_values( array_unique( $cartIds ) ),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array{lines: array<int, array<string, mixed>>, cart_ids: array<int>}|array{error: string, errors?: array}
     */
    private static function resolveGuestLines( array $items ): array
    {
        $lines  = [];
        $errors = [];

        foreach ( array_values( $items ) as $index => $item ) {
            $item      = (array) $item;
            $productId = (int) ( $item['product_id'] ?? 0 );
            $qty       = max( 1, (int) ( $item['qty'] ?? 1 ) );
            $variantId = ProductVariantService::normalizeNullableId( $item['variant_id'] ?? null );

            $merchantTenantId = request()->input( 'tenant_id' ) ?: ( function_exists( 'tenant' ) && tenant() ? tenant()->id : null );
            $product          = $merchantTenantId
                ? CrossTenantQueryService::getSingleRecordFromTenant(
                    $merchantTenantId,
                    Product::class,
                    fn ( $query ) => $query->where( 'id', $productId )->where( 'status', 'active' )
                )
                : Product::where( 'id', $productId )->where( 'status', 'active' )->first();

            if ( ! $product ) {
                $errors["items.$index.product_id"] = ['Product is not available.'];
                continue;
            }

            if ( self::productHasVariants( $product, $merchantTenantId ) && ! $variantId ) {
                $errors["items.$index.variant_id"] = ['A variant is required for this product.'];
                continue;
            }

            if ( $variantId && ! self::variantExists( $productId, $variantId, $merchantTenantId ) ) {
                $errors["items.$index.variant_id"] = ['Variant not found for this product.'];
                continue;
            }

            $unitPrice  = self::resolveTrustedUnitPrice( $product, $variantId, null, $item, $merchantTenantId );
            $stockError = self::assertStock( $product, $variantId, $qty, $merchantTenantId );
            if ( $stockError ) {
                $errors["items.$index.qty"] = [$stockError];
                continue;
            }

            $lines[] = self::buildLinePayload( $item, $product, $variantId, $qty, $unitPrice, null, null );
        }

        if ( $errors !== [] ) {
            return ['error' => 'Invalid items', 'errors' => $errors];
        }

        return ['lines' => $lines, 'cart_ids' => []];
    }

    private static function resolveTrustedUnitPrice(
        object $product,
        ?int $variantId,
        ?Cart $cart,
        array $item,
        string|int|null $merchantTenantId = null
    ): float {
        if ( $variantId ) {
            $variant = self::findVariant( (int) $product->id, $variantId, $merchantTenantId );
            if ( $variant ) {
                return ProductVariantService::resolveVariantUnitPrice( $variant, $product );
            }
        }

        if ( $cart && (float) $cart->product_price > 0 ) {
            return (float) $cart->product_price;
        }

        $client = ProductVariantService::toDecimal( $item['unit_price'] ?? null );
        if ( $client !== null && $client > 0 ) {
            $fallback = ProductVariantService::productFallbackPrice( $product );

            return $fallback > 0 ? $fallback : $client;
        }

        return ProductVariantService::productFallbackPrice( $product );
    }

    private static function assertStock(
        object $product,
        ?int $variantId,
        int $qty,
        string|int|null $merchantTenantId = null
    ): ?string {
        if ( $variantId ) {
            $variant = self::findVariant( (int) $product->id, $variantId, $merchantTenantId );
            if ( ! $variant ) {
                return 'Variant stock not found.';
            }
            if ( (int) $variant->qty < $qty ) {
                return 'Only ' . (int) $variant->qty . ' in stock for this variant.';
            }

            return null;
        }

        if ( (int) $product->qty < $qty ) {
            return 'Only ' . (int) $product->qty . ' in stock.';
        }

        return null;
    }

    private static function productHasVariants( object $product, string|int|null $merchantTenantId = null ): bool
    {
        if ( is_array( $product->variants ?? null ) && $product->variants !== [] ) {
            return true;
        }

        if ( $merchantTenantId ) {
            $tenant = Tenant::on( 'mysql' )->find( $merchantTenantId );
            if ( $tenant ) {
                $connection = CrossTenantQueryService::connectionForTenant( $tenant );

                return DB::connection( $connection )
                    ->table( 'product_variants' )
                    ->where( 'product_id', $product->id )
                    ->whereNull( 'deleted_at' )
                    ->exists();
            }
        }

        return ProductVariant::where( 'product_id', $product->id )->exists();
    }

    private static function variantExists( int $productId, int $variantId, string|int|null $merchantTenantId = null ): bool
    {
        return (bool) self::findVariant( $productId, $variantId, $merchantTenantId );
    }

    private static function findVariant( int $productId, int $variantId, string|int|null $merchantTenantId = null ): ?object
    {
        if ( $merchantTenantId ) {
            $tenant = Tenant::on( 'mysql' )->find( $merchantTenantId );
            if ( $tenant ) {
                $connection = CrossTenantQueryService::connectionForTenant( $tenant );

                return DB::connection( $connection )
                    ->table( 'product_variants' )
                    ->where( 'product_id', $productId )
                    ->where( 'id', $variantId )
                    ->whereNull( 'deleted_at' )
                    ->first();
            }
        }

        return ProductVariant::where( 'product_id', $productId )->where( 'id', $variantId )->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildLinePayload(
        array $item,
        object $product,
        ?int $variantId,
        int $qty,
        float $unitPrice,
        ?int $cartId,
        ?int $cartDetailId
    ): array {
        $options = $item['variant_options'] ?? [];
        if ( is_string( $options ) ) {
            $decoded = json_decode( $options, true );
            $options = is_array( $decoded ) ? $decoded : [];
        }

        $label = $item['variant_label'] ?? null;
        $sku   = $item['sku'] ?? null;
        $image = $item['image'] ?? $product->image;

        if ( $variantId && is_array( $product->variants ) ) {
            foreach ( $product->variants as $jsonVariant ) {
                $jsonVariant = (array) $jsonVariant;
                $jsonId      = (int) ( $jsonVariant['variant_id'] ?? $jsonVariant['id'] ?? 0 );
                if ( $jsonId === $variantId ) {
                    $label = $label ?: ( $jsonVariant['label'] ?? null );
                    $sku   = $sku ?: ( $jsonVariant['sku'] ?? null );
                    $image = $image ?: ( $jsonVariant['image'] ?? null );
                    if ( $options === [] ) {
                        $options = array_values( (array) ( $jsonVariant['options'] ?? [] ) );
                    }
                    break;
                }
            }
        }

        return [
            'line_key'         => $item['line_key'] ?? ( ( $cartId ?: $product->id ) . '-' . ( $cartDetailId ?: $variantId ?: '0' ) ),
            'cart_id'          => $cartId,
            'cart_detail_id'   => $cartDetailId,
            'product_id'       => (int) $product->id,
            'product_name'     => $item['product_name'] ?? $product->name,
            'qty'              => $qty,
            'unit_price'       => round( $unitPrice, 2 ),
            'line_total'       => round( $unitPrice * $qty, 2 ),
            'variant_id'       => $variantId,
            'variant_label'    => $label,
            'sku'              => $sku,
            'image'            => $image,
            'color'            => $item['color'] ?? null,
            'size'             => $item['size'] ?? null,
            'unit_id'          => ProductVariantService::normalizeNullableId( $item['unit_id'] ?? null ),
            'variant_options'  => array_values( (array) $options ),
        ];
    }

    private static function createOrderDetail( string $connectionName, Order $order, array $line ): void
    {
        $detail = new OrderDetails();
        $detail->setConnection( $connectionName );
        $detail->order_id   = $order->id;
        $detail->product_id = $line['product_id'];
        $detail->unit_id    = $line['unit_id'];
        $detail->size_id    = is_numeric( $line['size'] ?? null ) ? (int) $line['size'] : null;
        $detail->color_id   = is_numeric( $line['color'] ?? null ) ? (int) $line['color'] : null;
        $detail->sub_qty    = $line['qty'];
        $detail->rate       = $line['unit_price'];
        $detail->sub_total  = $line['line_total'];

        if ( Schema::connection( $connectionName )->hasColumn( 'order_details', 'variant_id' ) ) {
            $detail->variant_id = $line['variant_id'];
        }
        if ( Schema::connection( $connectionName )->hasColumn( 'order_details', 'variant_label' ) ) {
            $detail->variant_label = $line['variant_label'];
        }
        if ( Schema::connection( $connectionName )->hasColumn( 'order_details', 'variant_options' ) ) {
            $detail->variant_options = $line['variant_options'];
        }
        if ( Schema::connection( $connectionName )->hasColumn( 'order_details', 'product_name' ) ) {
            $detail->product_name = $line['product_name'];
        }
        if ( Schema::connection( $connectionName )->hasColumn( 'order_details', 'sku' ) ) {
            $detail->sku = $line['sku'];
        }
        if ( Schema::connection( $connectionName )->hasColumn( 'order_details', 'image' ) ) {
            $detail->image = $line['image'];
        }

        $detail->save();
    }

    private static function decrementLineStock( string $connectionName, Product $product, array $line ): void
    {
        $qty = (int) $line['qty'];
        if ( $qty <= 0 ) {
            return;
        }

        $variantId = ProductVariantService::normalizeNullableId( $line['variant_id'] ?? null );
        $vendorId  = (int) ( $product->vendor_id ?? $product->user_id ?? 0 ) ?: null;

        ProductVariantService::decrementStockOnConnection(
            $connectionName,
            (int) $product->id,
            ProductVariantService::normalizeNullableId( $line['unit_id'] ?? null ),
            is_numeric( $line['size'] ?? null ) ? (int) $line['size'] : null,
            is_numeric( $line['color'] ?? null ) ? (int) $line['color'] : null,
            $qty,
            $variantId,
            $vendorId
        );
    }

    private static function resolveMerchantTenantId( Request $request, array $lines ): string|int
    {
        if ( $request->filled( 'tenant_id' ) ) {
            return $request->input( 'tenant_id' );
        }

        $firstCartId = (int) ( $lines[0]['cart_id'] ?? 0 );
        if ( $firstCartId > 0 ) {
            $cartTenant = Cart::where( 'id', $firstCartId )->value( 'tenant_id' );
            if ( $cartTenant ) {
                return $cartTenant;
            }
        }

        return tenant()->id;
    }

    private static function generateUniqueOrderId( string $connectionName ): string
    {
        do {
            $orderId = 'OR' . str_pad( (string) mt_rand( 1, 999999 ), 6, '0', STR_PAD_LEFT );
            $exists  = DB::connection( $connectionName )->table( 'orders' )->where( 'order_id', $orderId )->exists();
        } while ( $exists );

        return $orderId;
    }

    private static function nullableEmail( mixed $email ): ?string
    {
        return self::nullableString( $email );
    }

    private static function nullableString( mixed $value ): ?string
    {
        $value = is_string( $value ) ? trim( $value ) : '';

        return $value !== '' ? $value : null;
    }
}
