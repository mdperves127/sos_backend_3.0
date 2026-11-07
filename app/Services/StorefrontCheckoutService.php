<?php

namespace App\Services;

use App\Enums\Status;
use App\Models\Cart;
use App\Models\DeliveryCharge;
use App\Models\Order;
use App\Models\OrderDetails;
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

        $paymentType = strtoupper( trim( (string) $request->input( 'payment_type', 'COD' ) ) );
        if ( $paymentType === '' ) {
            $paymentType = 'COD';
        }

        if ( ! in_array( $paymentType, ['COD', 'AAMARPAY', 'EPS', 'UDDOKTAPAY', 'MY-WALLET'], true ) ) {
            return response()->json( [
                'success' => false,
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => ['payment_type' => ['Unsupported payment type.']],
            ], 422 );
        }

        // Gateway payments still go through the legacy adapter for now.
        if ( in_array( $paymentType, ['AAMARPAY', 'EPS', 'UDDOKTAPAY'], true ) ) {
            return response()->json( [
                'success' => false,
                'status'  => 422,
                'message' => 'Use COD for this checkout payload, or complete gateway checkout via the legacy flow.',
                'errors'  => ['payment_type' => ['Gateway checkout for the new payload is not enabled yet. Use COD.']],
            ], 422 );
        }

        $customer = (array) $request->input( 'customer', [] );
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

        $resolvedLines = $isGuest
            ? self::resolveGuestLines( (array) $request->input( 'items', [] ) )
            : self::resolveAuthLines( (array) $request->input( 'items', [] ), (int) auth()->id() );

        if ( isset( $resolvedLines['error'] ) ) {
            return response()->json( [
                'success' => false,
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => $resolvedLines['errors'] ?? ['items' => [$resolvedLines['error']]],
            ], 422 );
        }

        /** @var array<int, array<string, mixed>> $lines */
        $lines    = $resolvedLines['lines'];
        $cartIds  = $resolvedLines['cart_ids'] ?? [];
        $subtotal = round( collect( $lines )->sum( 'line_total' ), 2 );
        $shipping = (float) $delivery['charge'];

        $couponInput = (array) $request->input( 'coupon', [] );
        $couponCode  = $couponInput['code'] ?? $request->input( 'coupon_code' );
        $couponCtx   = self::resolveCoupon(
            is_string( $couponCode ) ? $couponCode : null,
            $subtotal,
            $isGuest ? 0 : (int) auth()->id(),
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

        $connectionName = CrossTenantQueryService::connectionForTenant( $tenant );

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
                $merchantTenantId
            ) {
                // One order per product group (schema has singular product_id).
                $groups      = collect( $lines )->groupBy( 'product_id' );
                $savedOrders = [];
                $couponLeft  = $discount;
                $shippingLeft = $shipping;
                $deliveryApplied = false;
                $couponApplied   = false;

                foreach ( $groups as $productId => $groupLines ) {
                    $groupLines = $groupLines->values()->all();
                    $first      = $groupLines[0];
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

                    $groupDue = max( 0, round( $groupSubtotal + $groupShipping - $groupDiscount, 2 ) );

                    $orderId = self::generateUniqueOrderId( $connectionName );
                    $order   = new Order();
                    $order->setConnection( $connectionName );
                    $order->order_id        = $orderId;
                    $order->user_id         = $isGuest ? null : auth()->id();
                    $order->vendor_id       = $product->user_id;
                    $order->affiliator_id   = 0;
                    $order->product_id      = $product->id;
                    $order->name            = $customer['name'] ?? ( $formData['values']['name'] ?? null );
                    $order->phone           = $customer['phone'] ?? ( $formData['values']['phone'] ?? null );
                    $order->email           = $customer['email'] ?? ( $formData['values']['email'] ?? null );
                    $order->address         = $customer['address'] ?? ( $formData['values']['address'] ?? null );
                    $order->additional_note = $customer['notes'] ?? ( $formData['values']['notes'] ?? null );
                    $order->city            = $customer['city'] ?? null;
                    $order->variants        = array_map( fn ( $line ) => [
                        'variant_id'      => $line['variant_id'],
                        'id'              => $line['variant_id'],
                        'label'           => $line['variant_label'],
                        'qty'             => $line['qty'],
                        'rate'            => $line['unit_price'],
                        'price'           => $line['unit_price'],
                        'sku'             => $line['sku'],
                        'image'           => $line['image'],
                        'options'         => $line['variant_options'],
                        'color'           => $line['color'],
                        'size'            => $line['size'],
                        'unit_id'         => $line['unit_id'],
                        'product_name'    => $line['product_name'],
                        'line_key'        => $line['line_key'] ?? null,
                        'cart_id'         => $line['cart_id'] ?? null,
                        'cart_detail_id'  => $line['cart_detail_id'] ?? null,
                    ], $groupLines );
                    $order->afi_amount      = 0;
                    $order->product_amount  = $groupSubtotal;
                    $order->due_amount      = $groupDue;
                    $order->paid_amount     = 0;
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
                                $isGuest ? null : (int) auth()->id(),
                                $order->email
                            );
                        } catch ( \Throwable $e ) {
                            // Order already saved; coupon usage is best-effort.
                        }
                        $couponLeft   = max( 0, $couponLeft - $groupDiscount );
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
                    'orders'  => $savedOrders,
                    'total'   => $total,
                    'subtotal'=> $subtotal,
                    'shipping'=> $shipping,
                    'discount'=> $discount,
                ];
            } );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'success' => false,
                'status'  => 500,
                'message' => 'Order placement failed: ' . $e->getMessage(),
            ], 500 );
        }

        // Clear auth carts used in this checkout.
        if ( ! $isGuest && $cartIds !== [] ) {
            Cart::where( 'user_id', auth()->id() )
                ->whereIn( 'id', $cartIds )
                ->get()
                ->each
                ->delete();
        }

        $primary = $orderResult['orders'][0] ?? null;

        return response()->json( [
            'status'       => 200,
            'success'      => true,
            'message'      => 'Order placed successfully',
            'order_id'     => $primary['order_id'] ?? null,
            'order_ids'    => array_column( $orderResult['orders'], 'order_id' ),
            'orders_placed'=> count( $orderResult['orders'] ),
            'data'         => [
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
            if ( $type === 'email' && ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
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
}
