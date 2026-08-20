<?php

namespace App\Services;

use App\Enums\Status;
use App\Models\AdvancePayment;
use App\Models\Cart;
use App\Models\DeliveryCharge;
use App\Models\CourierCredential;
use App\Models\Order;
use App\Models\OrderDeliveryToCourier;
use App\Models\PendingBalance;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Tenant;
use App\Models\TenantCoupon;
use App\Services\CrossTenantQueryService;
use App\Services\TenantCouponService;
use App\Service\Vendor\ProductVariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            // Configure merchant tenant database connection.
            $connectionName = CrossTenantQueryService::connectionForTenant( $tenant );

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
            $savedOrders          = [];

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

                $unitProfitAmount = self::resolveDropshipperProfitAmount(
                    $orderMedia,
                    $resolvedPlacingTenantId,
                    $merchantTenantId,
                    $productid
                );
                $profit_amount = $unitProfitAmount * $totalqty;

                $vendorBalanceValue = ($vendor_balance && property_exists($vendor_balance, 'balance'))
                    ? $vendor_balance->balance
                    : 0;

                if ( $vendorBalanceValue >= $afi_amount ) {
                    $status = Status::Pending->value;
                } else {
                    $status = Status::Hold->value;
                }

                $isDirectWebsiteOrder = $userid <= 0
                    || in_array( $orderMedia, ['website', 'website-guest'], true );

                if ( $orderMedia === 'website-guest' ) {
                    $afi_amount = 0;
                    $userid = 0;
                }

                if ( $isDirectWebsiteOrder ) {
                    $status = Status::Pending->value;
                }

                // Dropshipper storefront only — merchant website/guest stays pending/hold
                if (
                    in_array( $orderMedia, ['website', 'website-guest'], true )
                    && function_exists( 'tenant' )
                    && tenant()
                    && ( tenant()->type ?? null ) === 'dropshipper'
                ) {
                    $status = Status::WaitingForDropshipperApproval->value;
                }

                $totalAmount         = convertfloat( $cart->product_price ) * convertfloat( $totalqty );
                $totaladvancepayment = $cart->advancepayment * $totalqty;

                $deliveryContext = self::resolveDeliveryCharge( $data );
                $deliveryCharge  = $deliveryContext['charge'];

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
                $order->affiliator_id       = $afi_amount > 0 ? $userid : 0;
                $order->product_id          = $product->id;
                $order->name                = $data['name'];
                $order->phone               = $data['phone'];
                $order->email               = $data['email'];
                $order->city                = $data['city'] ?? null;
                $order->address             = $data['address'];
                $order->variants            = $variants;
                $order->afi_amount          = $afi_amount;
                $order->profit_amount       = $profit_amount;
                $order->product_amount      = $totalAmount;
                $order->due_amount          = $totalDue;
                $order->status              = $status;
                $order->category_id         = $categoryId;
                $order->qty                 = $totalqty;
                $order->totaladvancepayment = $totaladvancepayment;
                $order->is_unlimited        = $is_unlimited;
                $order->delivery_charge     = $deliveryCharge;
                if ( ! empty( $deliveryContext['area'] ) ) {
                    $order->delivery_area = $deliveryContext['area'];
                }
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
                    $orderMedia
                ) {
                    $order->save();
                    self::decreaseProductStock( $connectionName, $productid, $totalqty, $variants, $cart, $product, $orderMedia );
                } );

                $savedOrders[] = [
                    'id'       => (int) $order->id,
                    'order_id' => (string) $order->order_id,
                ];

                if ( $saleDiscount > 0 && $tenantCoupon instanceof TenantCoupon ) {
                    try {
                        TenantCouponService::recordUsage(
                            $tenantCoupon,
                            $saleDiscount,
                            (int) $order->id,
                            $customerUserId,
                            $data['email'] ?? null
                        );
                        $couponAppliedInStore = true;
                    } catch ( \Throwable $couponError ) {
                        Log::warning( 'Checkout coupon usage failed after order save', [
                            'order_id' => $order->order_id,
                            'message'  => $couponError->getMessage(),
                        ] );
                    }
                }

                self::createCheckoutSideRecords(
                    $connectionName,
                    $tenant,
                    $order,
                    $product,
                    $userid,
                    $data,
                    $totalqty,
                    $totaladvancepayment,
                    $afi_amount,
                    $orderMedia
                );
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

            if ( $savedOrders === [] ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Checkout data is missing. No order was created.',
                ], 400 );
            }

            return response()->json( [
                'status'   => 200,
                'message'  => 'Checkout successfully!',
                'order_id' => $savedOrders[0]['order_id'] ?? null,
                'orders'   => $savedOrders,
            ] );
        } catch ( \Exception $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => $e->getMessage(),
            ] );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{charge: float, area: ?string, id: ?int}
     */
    public static function resolveDeliveryCharge( array $data ): array {
        $selection = self::extractDeliverySelection( $data );

        if ( $selection['id'] ) {
            $record = self::findDeliveryChargeRecord( $selection['id'] );

            if ( $record ) {
                $recordCharge = (float) $record->charge;

                if (
                    $selection['charge'] > 0
                    && abs( $recordCharge - $selection['charge'] ) > 0.001
                    && ! empty( $selection['area'] )
                ) {
                    $byArea = self::findDeliveryChargeByArea( (string) $selection['area'] );

                    if ( $byArea && abs( (float) $byArea->charge - $selection['charge'] ) < 0.001 ) {
                        return [
                            'charge' => (float) $byArea->charge,
                            'area'   => $byArea->area,
                            'id'     => (int) $byArea->id,
                        ];
                    }

                    return [
                        'charge' => $selection['charge'],
                        'area'   => $selection['area'],
                        'id'     => (int) $record->id,
                    ];
                }

                return [
                    'charge' => $recordCharge,
                    'area'   => $record->area,
                    'id'     => (int) $record->id,
                ];
            }
        }

        if ( $selection['area'] ) {
            $record = self::findDeliveryChargeByArea( $selection['area'] );

            if ( $record ) {
                return [
                    'charge' => (float) $record->charge,
                    'area'   => $record->area,
                    'id'     => (int) $record->id,
                ];
            }
        }

        if ( $selection['charge'] > 0 ) {
            return [
                'charge' => $selection['charge'],
                'area'   => $selection['area'],
                'id'     => $selection['id'],
            ];
        }

        if ( ! empty( $data['city'] ) ) {
            $city = $data['city'];

            if ( is_numeric( $city ) ) {
                $record = self::findDeliveryChargeRecord( (int) $city );

                if ( $record ) {
                    return [
                        'charge' => (float) $record->charge,
                        'area'   => $record->area,
                        'id'     => (int) $record->id,
                    ];
                }
            } elseif ( is_string( $city ) && ! self::isPlaceholderCity( $city ) ) {
                $record = self::findDeliveryChargeByArea( $city );

                if ( $record ) {
                    return [
                        'charge' => (float) $record->charge,
                        'area'   => $record->area,
                        'id'     => (int) $record->id,
                    ];
                }
            }
        }

        return [
            'charge' => 0.0,
            'area'   => null,
            'id'     => null,
        ];
    }

    /**
     * Read delivery selection from explicit delivery fields and guest shipping row id.
     *
     * @param  array<string, mixed>  $data
     * @return array{id: ?int, area: ?string, charge: float}
     */
    private static function extractDeliverySelection( array $data ): array {
        $id     = null;
        $area   = null;
        $charge = 0.0;

        $payload = $data['delivery_charge']
            ?? $data['deliveryCharge']
            ?? $data['delivery']
            ?? $data['shipping_charge']
            ?? null;

        if ( is_array( $payload ) ) {
            $id = self::numericId( $payload['id'] ?? $payload['delivery_charge_id'] ?? null );
            $area = $payload['area'] ?? $payload['name'] ?? $payload['label'] ?? null;

            if ( isset( $payload['charge'] ) && is_numeric( $payload['charge'] ) ) {
                $charge = (float) $payload['charge'];
            }
        } elseif ( is_numeric( $payload ) ) {
            $id = self::numericId( $payload );
        } elseif ( is_string( $payload ) && trim( $payload ) !== '' ) {
            if ( is_numeric( trim( $payload ) ) ) {
                $id = self::numericId( trim( $payload ) );
            } else {
                $area = trim( $payload );
            }
        }

        foreach ( ['delivery_charge_id', 'delivery_area_id'] as $key ) {
            if ( ! $id && ! empty( $data[$key] ) ) {
                $id = self::numericId( $data[$key] );
            }
        }

        foreach ( ['delivery_area', 'shipping_area'] as $key ) {
            $value = $data[$key] ?? null;

            if ( $value === null || $value === '' ) {
                continue;
            }

            if ( ! $id && is_numeric( $value ) ) {
                $id = self::numericId( $value );
                continue;
            }

            if ( ! $area && is_string( $value ) && trim( $value ) !== '' ) {
                $area = trim( $value );
            }
        }

        if ( ! $id ) {
            $rowId = self::numericId( $data['id'] ?? null );

            if ( $rowId && ! self::datasRowIdIsProductReference( $data, $rowId ) ) {
                $id = $rowId;
            }
        }

        return [
            'id'     => $id,
            'area'   => is_string( $area ) && $area !== '' ? $area : null,
            'charge' => max( 0, $charge ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function datasRowIdIsProductReference( array $data, int $rowId ): bool {
        if ( self::numericId( $data['product_id'] ?? null ) === $rowId ) {
            return true;
        }

        foreach ( $data['variants'] ?? [] as $variant ) {
            if ( ! is_array( $variant ) ) {
                continue;
            }

            $variantProductId = self::numericId( $variant['id'] ?? null );

            if (
                $variantProductId === $rowId
                && (
                    isset( $variant['variant_id'] )
                    || isset( $variant['color'] )
                    || isset( $variant['size'] )
                    || isset( $variant['unit'] )
                    || isset( $variant['qty'] )
                )
            ) {
                return true;
            }
        }

        if (
            isset( $data['qty'], $data['id'] )
            && ! isset( $data['name'], $data['phone'], $data['address'] )
        ) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function deliveryChargePayloadFromRequest( $request ): array {
        if ( ! $request ) {
            return [];
        }

        $payload = $request->input( 'delivery_charge' )
            ?? $request->input( 'deliveryCharge' )
            ?? $request->input( 'delivery' );

        if ( is_array( $payload ) && $payload !== [] ) {
            $resolved = self::resolveDeliveryCharge( ['delivery_charge' => $payload] );

            if ( $resolved['charge'] > 0 || $resolved['id'] ) {
                return array_filter( [
                    'id'     => $resolved['id'],
                    'area'   => $resolved['area'],
                    'charge' => $resolved['charge'],
                ], fn( $value ) => $value !== null && $value !== '' );
            }
        }

        if ( is_numeric( $payload ) ) {
            $resolved = self::resolveDeliveryCharge( ['delivery_charge' => $payload] );

            if ( $resolved['charge'] > 0 ) {
                return [
                    'id'     => $resolved['id'],
                    'area'   => $resolved['area'],
                    'charge' => $resolved['charge'],
                ];
            }
        }

        foreach ( ['delivery_charge_id', 'delivery_area_id', 'delivery_area', 'shipping_area'] as $key ) {
            $value = $request->input( $key );

            if ( $value === null || $value === '' ) {
                continue;
            }

            $resolved = self::resolveDeliveryCharge( [$key => $value] );

            if ( $resolved['charge'] > 0 ) {
                return [
                    'id'     => $resolved['id'],
                    'area'   => $resolved['area'],
                    'charge' => $resolved['charge'],
                ];
            }
        }

        $city = $request->input( 'city' );

        if ( $city !== null && $city !== '' && ! self::isPlaceholderCity( (string) $city ) ) {
            $resolved = self::resolveDeliveryCharge( ['city' => $city] );

            if ( $resolved['charge'] > 0 ) {
                return [
                    'id'     => $resolved['id'],
                    'area'   => $resolved['area'],
                    'charge' => $resolved['charge'],
                ];
            }
        }

        foreach ( (array) $request->input( 'datas', [] ) as $dataRow ) {
            if ( ! is_array( $dataRow ) ) {
                continue;
            }

            $resolved = self::resolveDeliveryCharge( $dataRow );

            if ( $resolved['charge'] > 0 || $resolved['id'] ) {
                return array_filter( [
                    'id'     => $resolved['id'],
                    'area'   => $resolved['area'],
                    'charge' => $resolved['charge'],
                ], fn( $value ) => $value !== null && $value !== '' );
            }
        }

        return [];
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $requestDatas
     * @return array<int, array<string, mixed>>
     */
    public static function enrichCheckoutDatasWithDelivery( $request, iterable $requestDatas ): array {
        $rootDelivery = self::deliveryChargePayloadFromRequest( $request );

        return collect( $requestDatas )
            ->map( function ( $data ) use ( $rootDelivery ) {
                $data = (array) $data;

                if ( $rootDelivery !== [] ) {
                    $existing = (array) ( $data['delivery_charge'] ?? $data['deliveryCharge'] ?? [] );
                    $data['delivery_charge'] = array_merge( $existing, $rootDelivery );
                }

                $resolved = self::resolveDeliveryCharge( $data );

                if ( $resolved['charge'] > 0 ) {
                    $data['delivery_charge'] = [
                        'id'     => $resolved['id'],
                        'area'   => $resolved['area'],
                        'charge' => $resolved['charge'],
                    ];

                    if ( self::isPlaceholderCity( (string) ( $data['city'] ?? '' ) ) && ! empty( $resolved['area'] ) ) {
                        $data['city'] = $resolved['area'];
                    }
                }

                return $data;
            } )
            ->values()
            ->all();
    }

    private static function findDeliveryChargeRecord( int $id ): ?DeliveryCharge {
        if ( $id < 1 ) {
            return null;
        }

        return DeliveryCharge::query()->find( $id );
    }

    private static function findDeliveryChargeByArea( string $area ): ?DeliveryCharge {
        $area = trim( $area );

        if ( $area === '' || self::isPlaceholderCity( $area ) ) {
            return null;
        }

        $normalized = strtolower( preg_replace( '/[\s_\-]+/', '', $area ) ?? '' );

        return DeliveryCharge::query()
            ->where( 'area', $area )
            ->orWhere( 'area', 'like', '%' . $area . '%' )
            ->get()
            ->first( function ( DeliveryCharge $record ) use ( $normalized ) {
                $recordArea = strtolower( preg_replace( '/[\s_\-]+/', '', (string) $record->area ) ?? '' );

                return $recordArea !== '' && $recordArea === $normalized;
            } )
            ?? DeliveryCharge::query()
                ->where( 'area', $area )
                ->orWhere( 'area', 'like', '%' . $area . '%' )
                ->first();
    }

    private static function numericId( mixed $value ): ?int {
        if ( $value === null || $value === '' || ! is_numeric( $value ) ) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function isPlaceholderCity( string $city ): bool {
        $city = strtolower( trim( $city ) );

        return $city === ''
            || in_array( $city, ['city', 'select city', 'select delivery area', 'null', 'undefined'], true );
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
        object $product,
        ?string $orderMedia = null
    ): void {
        if ( ! self::shouldDecreaseStock( $cart, $product, $orderMedia ) ) {
            return;
        }

        if ( $variants === [] ) {
            $variants = [['qty' => $totalqty]];
        }

        $vendorId = (int) ( $product->vendor_id ?? $product->user_id ?? 0 );

        foreach ( $variants as $variant ) {
            $variant    = (array) $variant;
            $variantQty = self::resolveVariantQuantity( $variant, $totalqty );
            $variantId  = (int) ( $variant['variant_id'] ?? $variant['id'] ?? 0 ) ?: null;

            ProductVariantService::decrementStockOnConnection(
                $connectionName,
                $productId,
                ProductVariantService::normalizeNullableId( self::resolveCheckoutAttributeId( $variant, 'unit', 'unit_id' ) ),
                ProductVariantService::normalizeNullableId( self::resolveCheckoutSizeId( $variant ) ),
                ProductVariantService::normalizeNullableId( self::resolveCheckoutAttributeId( $variant, 'color', 'color_id' ) ),
                $variantQty,
                $variantId,
                $vendorId > 0 ? $vendorId : null
            );
        }
    }

    private static function shouldDecreaseStock( Cart $cart, object $product, ?string $orderMedia ): bool {
        $purchaseType = trim( (string) ( $cart->purchase_type ?? '' ) );

        if ( $purchaseType === '' ) {
            $purchaseType = ( $product->selling_type ?? 'single' ) === 'bulk' ? 'bulk' : 'single';
        }

        if ( in_array( $orderMedia, ['website', 'website-guest', 'Direct'], true ) ) {
            return $purchaseType === 'single' || (int) ( $product->is_connect_bulk_single ?? 0 ) === 1;
        }

        return $purchaseType === 'single' || (int) ( $product->is_connect_bulk_single ?? 0 ) === 1;
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private static function resolveCheckoutAttributeId( array $variant, string $key, ?string $flatKey = null ): mixed {
        $value = $variant[$key] ?? ( $flatKey ? ( $variant[$flatKey] ?? null ) : null );

        if ( is_array( $value ) ) {
            return $value['id'] ?? null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    private static function resolveCheckoutSizeId( array $variant ): mixed {
        return self::resolveCheckoutAttributeId( $variant, 'size', 'size_id' )
            ?? self::resolveCheckoutAttributeId( $variant, 'variation', 'variation_id' );
    }

    /**
     * Optional post-checkout records. Failures are logged but do not roll back the order.
     *
     * @param  object  $product
     * @param  array<string, mixed>  $data
     */
    private static function createCheckoutSideRecords(
        string $connectionName,
        Tenant $tenant,
        Order $order,
        object $product,
        int $userid,
        array $data,
        int $totalqty,
        float $totaladvancepayment,
        float $afi_amount,
        ?string $orderMedia
    ): void {
        $vendorId = $product->user_id ?? $product->vendor_id ?? null;

        try {
            $courierCredentials = CrossTenantQueryService::queryTenant(
                $tenant,
                CourierCredential::class,
                function ( $query ) use ( $vendorId ) {
                    $query->where( 'vendor_id', $vendorId )
                        ->where( 'status', 'active' )
                        ->where( 'default', 'yes' );
                }
            );

            if ( $courierCredentials->isNotEmpty() ) {
                $defaultCourier = $courierCredentials->first();
                $isPathao       = CrossTenantQueryService::queryTenant(
                    $tenant,
                    CourierCredential::class,
                    function ( $query ) use ( $vendorId ) {
                        $query->where( 'vendor_id', $vendorId )
                            ->where( 'status', 'active' )
                            ->where( 'default', 'yes' )
                            ->where( 'courier_name', 'pathao' );
                    }
                )->isNotEmpty();
                $isRedx = CrossTenantQueryService::queryTenant(
                    $tenant,
                    CourierCredential::class,
                    function ( $query ) use ( $vendorId ) {
                        $query->where( 'vendor_id', $vendorId )
                            ->where( 'status', 'active' )
                            ->where( 'default', 'yes' )
                            ->where( 'courier_name', 'redx' );
                    }
                )->isNotEmpty();

                $delivery = new OrderDeliveryToCourier();
                $delivery->setConnection( $connectionName );
                $delivery->order_id            = $order->id;
                $delivery->vendor_id           = $vendorId;
                $delivery->affiliator_id       = $userid;
                $delivery->merchant_order_id   = $order->order_id;
                $delivery->recipient_name      = $data['name'];
                $delivery->recipient_phone     = $data['phone'];
                $delivery->recipient_address   = $data['address'];
                $delivery->courier_id          = $data['courier_id'] ?? $defaultCourier?->id;
                $delivery->item_weight         = $data['item_weight'] ?? null;
                $delivery->recipient_city      = $isPathao ? ( $data['city_id'] ?? null ) : null;
                $delivery->recipient_zone      = $isPathao ? ( $data['zone_id'] ?? null ) : null;
                $delivery->recipient_area      = $isPathao ? ( $data['area_id'] ?? null ) : ( $isRedx ? ( $data['area_id'] ?? null ) : null );
                $delivery->area_name           = $isRedx ? ( $data['area_name'] ?? null ) : null;
                $delivery->delivery_type       = 48;
                $delivery->item_type           = $data['item_type'] ?? null;
                $delivery->special_instruction = $data['special_instruction'] ?? null;
                $delivery->item_quantity       = $data['item_quantity'] ?? $totalqty;
                $delivery->amount_to_collect   = $order->due_amount;
                $delivery->item_description    = $data['item_description'] ?? null;
                $delivery->save();
            }
        } catch ( \Throwable $e ) {
            Log::warning( 'Checkout courier record failed after order save', [
                'order_id' => $order->order_id,
                'message'  => $e->getMessage(),
            ] );
        }

        if ( $totaladvancepayment > 0 ) {
            try {
                $advance = new AdvancePayment();
                $advance->setConnection( $connectionName );
                $advance->vendor_id    = $vendorId;
                $advance->affiliate_id = $userid;
                $advance->product_id   = $product->id;
                $advance->qty          = $totalqty;
                $advance->amount       = $totaladvancepayment;
                $advance->order_id     = $order->id;
                $advance->save();
            } catch ( \Throwable $e ) {
                Log::warning( 'Checkout advance payment record failed after order save', [
                    'order_id' => $order->order_id,
                    'message'  => $e->getMessage(),
                ] );
            }
        }

        $isDirectWebsiteOrder = $userid <= 0
            || in_array( $orderMedia, ['website', 'website-guest'], true );

        if ( ! $isDirectWebsiteOrder && $userid > 0 && $afi_amount > 0 ) {
            try {
                $pending = new PendingBalance();
                $pending->setConnection( $connectionName );
                $pending->affiliator_id = $userid;
                $pending->product_id    = $product->id;
                $pending->order_id      = $order->id;
                $pending->qty           = $totalqty;
                $pending->amount        = $afi_amount;
                $pending->status        = Status::Pending->value;
                $pending->save();
            } catch ( \Throwable $e ) {
                Log::warning( 'Checkout pending balance record failed after order save', [
                    'order_id' => $order->order_id,
                    'message'  => $e->getMessage(),
                ] );
            }
        }
    }

    /**
     * Unit profit set by the dropshipper on their product_details profile.
     */
    private static function resolveDropshipperProfitAmount(
        ?string $orderMedia,
        string|int|null $placingTenantId,
        string|int|null $merchantTenantId,
        $productId
    ): float {
        if ( ! in_array( $orderMedia, ['dropshipper', 'Affiliator'], true ) ) {
            return 0.0;
        }

        // Prefer current tenant product_details when checkout runs inside dropshipper context
        if ( function_exists( 'tenant' ) && tenant() && ( tenant()->type ?? null ) === 'dropshipper' ) {
            $detail = ProductDetails::query()
                ->where( 'product_id', $productId )
                ->where( 'status', 1 )
                ->when( $merchantTenantId, fn ( $q ) => $q->where( 'tenant_id', $merchantTenantId ) )
                ->first();

            if ( $detail ) {
                return (float) ( $detail->profit_amount ?? 0 );
            }
        }

        if ( ! $placingTenantId ) {
            return 0.0;
        }

        $detail = CrossTenantQueryService::getSingleRecordFromTenant(
            $placingTenantId,
            ProductDetails::class,
            function ( $query ) use ( $productId, $merchantTenantId ) {
                $query->where( 'product_id', $productId )
                    ->where( 'status', 1 );

                if ( $merchantTenantId ) {
                    $query->where( 'tenant_id', $merchantTenantId );
                }
            }
        );

        return (float) ( $detail->profit_amount ?? 0 );
    }
}
