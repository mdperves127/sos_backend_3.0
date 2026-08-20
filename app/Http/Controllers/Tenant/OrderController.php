<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\CartDetails;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Services\CrossTenantQueryService;
use App\Enums\Status;
use App\Http\Requests\ProductRequest;
use App\Services\ProductCheckoutService;
use App\Services\TenantCouponService;
use App\Models\TenantCoupon;

class OrderController extends Controller
{
    function guestStore( Request $request ) {
        $requestDatas = collect(
            ProductCheckoutService::enrichCheckoutDatasWithDelivery(
                $request,
                $this->normalizeGuestDatas( $request )->all()
            )
        );

        if ( $requestDatas->isEmpty() ) {
            return responsejson( 'Checkout data is required for guest checkout.', 'fail' );
        }

        $shippingTemplate = $requestDatas->first();
        $checkoutEntries = $this->resolveGuestCheckoutEntries( $request, $requestDatas, $shippingTemplate );

        if ( $checkoutEntries === [] ) {
            return responsejson( 'Product information is missing for guest checkout.', 'fail' );
        }

        $requestedCartId = (int) $request->input( 'cart_id', 0 );
        $paymentType = $request->input( 'payment_type', 'aamarpay' );
        $couponContext = $this->resolveCheckoutCouponContext(
            $request->input( 'coupon_code' ),
            $this->estimateGuestCheckoutTotal( $checkoutEntries, $requestedCartId, $requestDatas, $shippingTemplate ),
            0,
            data_get( $shippingTemplate, 'email' )
        );
        if ( isset( $couponContext['error'] ) ) {
            return responsejson( $couponContext['error'], 'fail' );
        }

        $placed = 0;
        $failed = [];
        $couponApplied = false;
        $placedOrderIds = [];

        foreach ( $checkoutEntries as $entry ) {
            $cart = $entry['cart'];
            $tenantId = $entry['tenant_id'];
            $entryDatas = $entry['datas'];
            $createdGuestCart = false;

            if ( !$tenantId ) {
                $failed[] = [
                    'cart_id' => $cart?->id,
                    'message' => 'Missing tenant information',
                ];
                continue;
            }

            $explicitProductId = $this->resolveExplicitGuestProductId( $request, $entryDatas );

            if ( $cart && $explicitProductId && (int) $cart->product_id !== (int) $explicitProductId ) {
                $cart = null;
            }

            if ( !$cart ) {
                $productId = $explicitProductId ?: $this->resolveGuestProductId( $request, null, $tenantId, $entryDatas );

                if ( !$productId ) {
                    $failed[] = [
                        'message' => 'Product information is missing for guest checkout.',
                    ];
                    continue;
                }

                $product = CrossTenantQueryService::getSingleRecordFromTenant(
                    $tenantId,
                    Product::class,
                    fn( $query ) => $query->where( ['id' => $productId, 'status' => 'active'] )
                );

                if ( !$product ) {
                    $failed[] = [
                        'message' => 'Product currently not available',
                    ];
                    continue;
                }

                $guestCart = $this->createGuestCheckoutCart(
                    $request,
                    $product,
                    $tenantId,
                    $entryDatas,
                    $entry['purchase_type'] ?? null
                );

                if ( isset( $guestCart['error'] ) ) {
                    $failed[] = [
                        'message' => $guestCart['error'],
                    ];
                    continue;
                }

                $cart = $guestCart['cart'];
                $createdGuestCart = true;
            }

            $product = CrossTenantQueryService::getSingleRecordFromTenant(
                $tenantId,
                Product::class,
                fn( $query ) => $query->where( ['id' => $cart->product_id, 'status' => 'active'] )
            );

            if ( !$product ) {
                if ( $createdGuestCart ) {
                    $cart->delete();
                }

                $failed[] = [
                    'cart_id' => $cart->id,
                    'message' => 'Product currently not available',
                ];
                continue;
            }

            $checkoutDatas = $createdGuestCart
                ? $entryDatas->toArray()
                : $this->resolveGuestCheckoutDatasForCart(
                    $cart,
                    $requestedCartId,
                    $requestDatas,
                    $shippingTemplate
                );

            $checkoutDatas = collect( $checkoutDatas )
                ->map( fn( $data ) => $this->mergeCheckoutPayloadWithCart( $cart, $shippingTemplate, (array) $data ) )
                ->values()
                ->all();

            $checkoutDatas = $this->normalizeCheckoutDataVariants(
                $checkoutDatas,
                (int) ( $cart->product_qty ?? 0 )
            );

            $checkoutDatas = ProductCheckoutService::enrichCheckoutDatasWithDelivery(
                $request,
                $checkoutDatas
            );

            $totalqty = $this->resolveCheckoutTotalQty( $checkoutDatas, (int) ( $cart->product_qty ?? 0 ), $cart );

            $validationError = $this->validateCartForCheckout( $cart, $product, $totalqty, $checkoutDatas, true );
            if ( $validationError ) {
                if ( $createdGuestCart ) {
                    $cart->delete();
                }

                $failed[] = [
                    'cart_id' => $cart->id,
                    'message' => $validationError,
                ];
                continue;
            }

            $response = ProductCheckoutService::store(
                $cart->id,
                $product->id,
                $totalqty,
                0,
                $checkoutDatas,
                $paymentType,
                $tenantId,
                null,
                'website-guest',
                $couponApplied ? null : $couponContext['coupon'],
                $couponApplied ? 0 : $couponContext['discount']
            );

            $payload = method_exists( $response, 'getContent' )
                ? json_decode( $response->getContent(), true )
                : null;

            if ( ( $payload['status'] ?? null ) === 200 ) {
                if ( ! $couponApplied && $couponContext['coupon'] ) {
                    $couponApplied = true;
                }
                if ( ! empty( $payload['order_id'] ) ) {
                    $placedOrderIds[] = $payload['order_id'];
                }
                if ( ! empty( $payload['orders'] ) && is_array( $payload['orders'] ) ) {
                    foreach ( $payload['orders'] as $savedOrder ) {
                        if ( ! empty( $savedOrder['order_id'] ) ) {
                            $placedOrderIds[] = $savedOrder['order_id'];
                        }
                    }
                }
                $placed++;
                continue;
            }

            if ( $createdGuestCart ) {
                $cart->delete();
            }

            $failed[] = [
                'cart_id' => $cart->id,
                'message' => $payload['message'] ?? 'Checkout failed',
            ];
        }

        if ( $placed === 0 ) {
            return response()->json( [
                'status'  => 400,
                'message' => $failed[0]['message'] ?? 'Checkout failed',
                'failed'  => $failed,
            ], 400 );
        }

        return response()->json( [
            'status'        => 200,
            'message'       => $placed === 1
                ? 'Checkout successfully!'
                : $placed . ' orders placed successfully',
            'orders_placed' => $placed,
            'order_id'      => $placedOrderIds[0] ?? null,
            'order_ids'     => array_values( array_unique( $placedOrderIds ) ),
            'failed'        => $failed,
        ] );
    }

    function store( ProductRequest $request ) {
        $user = auth()->user();
        $requestDatas = ProductCheckoutService::enrichCheckoutDatasWithDelivery(
            $request,
            $request->input( 'datas', [] )
        );
        $shippingTemplate = $requestDatas[0] ?? [];
        $paymentType = $request->input( 'payment_type', 'aamarpay' );

        $carts = Cart::query()
            ->where( 'user_id', $user->id )
            ->with( ['cartDetails.color', 'cartDetails.size', 'cartDetails.unit'] )
            ->get();

        if ( $carts->isEmpty() ) {
            return responsejson( 'Your cart is empty.', 'fail' );
        }

        $requestedCart = $carts->firstWhere( 'id', (int) $request->cart_id );
        if ( !$requestedCart ) {
            return responsejson( 'Cart not found or missing tenant information', 'fail' );
        }

        $couponContext = $this->resolveCheckoutCouponContext(
            $request->input( 'coupon_code' ),
            $this->estimateAuthenticatedCheckoutTotal( $carts, (int) $request->cart_id, $requestDatas, $shippingTemplate ),
            (int) $user->id,
            data_get( $shippingTemplate, 'email' )
        );
        if ( isset( $couponContext['error'] ) ) {
            return responsejson( $couponContext['error'], 'fail' );
        }

        $placed = 0;
        $failed = [];
        $couponApplied = false;
        $placedOrderIds = [];

        foreach ( $carts as $cart ) {
            if ( !$cart->tenant_id ) {
                $failed[] = [
                    'cart_id' => $cart->id,
                    'message' => 'Missing tenant information',
                ];
                continue;
            }

            $product = CrossTenantQueryService::getSingleRecordFromTenant(
                $cart->tenant_id,
                Product::class,
                fn( $query ) => $query->where( ['id' => $cart->product_id, 'status' => 'active'] )
            );

            if ( !$product ) {
                $failed[] = [
                    'cart_id' => $cart->id,
                    'message' => 'Product currently not available',
                ];
                continue;
            }

            $checkoutDatas = $this->resolveCheckoutDatasForCart(
                $cart,
                (int) $request->cart_id,
                $requestDatas,
                $shippingTemplate
            );

            $checkoutDatas = $this->normalizeCheckoutDataVariants(
                $checkoutDatas,
                (int) ( $cart->product_qty ?? 0 )
            );

            $totalqty = $this->resolveCheckoutTotalQty( $checkoutDatas, (int) ( $cart->product_qty ?? 0 ), $cart );

            $validationError = $this->validateCartForCheckout( $cart, $product, $totalqty, $checkoutDatas, true );
            if ( $validationError ) {
                $failed[] = [
                    'cart_id' => $cart->id,
                    'message' => $validationError,
                ];
                continue;
            }

            $response = ProductCheckoutService::store(
                $cart->id,
                $product->id,
                $totalqty,
                $user->id,
                $checkoutDatas,
                $paymentType,
                $cart->tenant_id,
                null,
                'website',
                $couponApplied ? null : $couponContext['coupon'],
                $couponApplied ? 0 : $couponContext['discount']
            );

            $payload = method_exists( $response, 'getContent' )
                ? json_decode( $response->getContent(), true )
                : null;

            if ( ( $payload['status'] ?? null ) === 200 ) {
                if ( ! $couponApplied && $couponContext['coupon'] ) {
                    $couponApplied = true;
                }
                if ( ! empty( $payload['order_id'] ) ) {
                    $placedOrderIds[] = $payload['order_id'];
                }
                if ( ! empty( $payload['orders'] ) && is_array( $payload['orders'] ) ) {
                    foreach ( $payload['orders'] as $savedOrder ) {
                        if ( ! empty( $savedOrder['order_id'] ) ) {
                            $placedOrderIds[] = $savedOrder['order_id'];
                        }
                    }
                }
                $placed++;
                continue;
            }

            $failed[] = [
                'cart_id' => $cart->id,
                'message' => $payload['message'] ?? 'Checkout failed',
            ];
        }

        if ( $placed === 0 ) {
            return response()->json( [
                'status'  => 400,
                'message' => $failed[0]['message'] ?? 'Checkout failed',
                'failed'  => $failed,
            ], 400 );
        }

        return response()->json( [
            'status'        => 200,
            'message'       => $placed === 1
                ? 'Checkout successfully!'
                : $placed . ' orders placed successfully',
            'orders_placed' => $placed,
            'order_id'      => $placedOrderIds[0] ?? null,
            'order_ids'     => array_values( array_unique( $placedOrderIds ) ),
            'failed'        => $failed,
        ] );
    }

    private function validateCartForCheckout( Cart $cart, $product, int $totalqty, array $checkoutDatas = [], bool $lenientStock = false ): ?string {
        if ( $cart->purchase_type == 'single' && $product->selling_type == 'bulk' ) {
            return 'Something is wrong delete the cart.';
        }

        if ( $cart->purchase_type == 'single' || $product->is_connect_bulk_single == 1 ) {
            $requestedQty = max( 1, $totalqty );
            $cartQty      = (int) ( $cart->product_qty ?? 0 );

            if ( $cartQty > 0 ) {
                $requestedQty = $cartQty;
            }

            $variantLines = $this->extractCheckoutVariants( $checkoutDatas )
                ->filter( function ( $variant ) {
                    $variant = (array) $variant;

                    return $this->checkoutVariantHasIdentity( $variant ) && (int) ( $variant['qty'] ?? 0 ) > 0;
                } );

            $availableQty = $this->resolveAvailableProductQuantity( $cart->tenant_id, $product );

            if ( $variantLines->isNotEmpty() ) {
                foreach ( $variantLines as $variant ) {
                    $variant      = (array) $variant;
                    $lineQty      = (int) ( $variant['qty'] ?? $requestedQty );
                    $lineAvailable = max(
                        $availableQty,
                        $this->resolveVariantAvailableQuantity( $cart->tenant_id, $product, $variant )
                    );

                    if ( $this->isStockInsufficient( $lineAvailable, $lineQty, $lenientStock ) ) {
                        return 'Product quantity not available!';
                    }
                }
            } elseif ( $this->isStockInsufficient( $availableQty, $requestedQty, $lenientStock ) ) {
                return 'Product quantity not available!';
            }
        }

        if ( $product->status == Status::Pending->value ) {
            return 'The product under construction!';
        }

        return null;
    }

    private function isStockInsufficient( int $availableQty, int $requestedQty, bool $lenientStock ): bool {
        if ( $requestedQty < 1 ) {
            return true;
        }

        if ( $availableQty >= $requestedQty ) {
            return false;
        }

        // Website/guest products often have qty 0 because inventory was never filled in.
        // Only block when we know there is tracked stock and it is too low.
        if ( $lenientStock && $availableQty < 1 ) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checkoutDatas
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCheckoutDataVariants( array $checkoutDatas, int $fallbackQty = 1 ): array {
        $fallbackQty = max( 1, $fallbackQty );

        return array_map( function ( $data ) use ( $fallbackQty ) {
            $data     = (array) $data;
            $lineQty  = (int) ( $data['qty'] ?? $data['product_qty'] ?? $data['quantity'] ?? $fallbackQty );
            $lineQty  = $lineQty > 0 ? $lineQty : $fallbackQty;
            $variants = $data['variants'] ?? [];

            if ( !is_array( $variants ) ) {
                $variants = [];
            }

            if ( $variants !== [] && !array_is_list( $variants ) && (
                isset( $variants['variant_id'] )
                || isset( $variants['id'] )
                || isset( $variants['qty'] )
                || isset( $variants['quantity'] )
                || isset( $variants['color'] )
                || isset( $variants['size'] )
                || isset( $variants['unit'] )
            ) ) {
                $variants = [$variants];
            }

            $variants = array_values( array_filter(
                $variants,
                fn( $variant ) => ! $this->isAttributeDefinition( (array) $variant )
            ) );

            if ( $variants === [] ) {
                $variants = [['qty' => $lineQty]];
            } else {
                $variants = $this->mergeSplitAttributeVariants(
                    $this->normalizeVariantList( $variants, $lineQty ),
                    $lineQty
                );
            }

            $data['variants'] = array_values( $variants );

            return $data;
        }, $checkoutDatas );
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVariantList( array $variants, int $fallbackQty = 1 ): array {
        $fallbackQty = max( 1, $fallbackQty );

        return array_values( array_map( function ( $variant ) use ( $fallbackQty ) {
            $variant = (array) $variant;
            $qty     = (int) ( $variant['qty'] ?? $variant['quantity'] ?? 0 );

            $variant['qty'] = $qty > 0 ? $qty : $fallbackQty;

            return $variant;
        }, $variants ) );
    }

    /**
     * @param  array<int, array<string, mixed>>  $checkoutDatas
     */
    private function resolveCheckoutTotalQty( array $checkoutDatas, int $cartProductQty = 0, ?Cart $cart = null ): int {
        if ( $cart && (int) ( $cart->product_qty ?? 0 ) > 0 ) {
            return (int) $cart->product_qty;
        }

        $totalqty = (int) $this->extractCheckoutVariants( $checkoutDatas )->sum( 'qty' );

        if ( $totalqty < 1 ) {
            $totalqty = (int) collect( $checkoutDatas )->max( function ( $data ) {
                $data = (array) $data;

                return (int) ( $data['qty'] ?? $data['product_qty'] ?? $data['quantity'] ?? 0 );
            } );
        }

        if ( $totalqty < 1 ) {
            $totalqty = $cartProductQty;
        }

        if ( $totalqty < 1 && $cart ) {
            if ( ! $cart->relationLoaded( 'cartDetails' ) ) {
                $cart->load( 'cartDetails' );
            }

            $totalqty = (int) $cart->cartDetails->sum( 'qty' );
        }

        return max( 0, $totalqty );
    }

    private function extractCheckoutVariants( array $checkoutDatas ) {
        return collect( $checkoutDatas )
            ->pluck( 'variants' )
            ->filter()
            ->flatMap( fn( $variants ) => is_array( $variants ) ? $variants : [] )
            ->map( fn( $variant ) => (array) $variant )
            ->reject( fn( $variant ) => $this->isAttributeDefinition( $variant ) )
            ->values();
    }

    private function isAttributeDefinition( array $variant ): bool {
        if ( isset( $variant['options'] ) && is_array( $variant['options'] ) ) {
            return true;
        }

        $hasStockIdentity = ! empty( $variant['variant_id'] )
            || isset( $variant['qty'] )
            || isset( $variant['quantity'] )
            || isset( $variant['color'] )
            || isset( $variant['size'] )
            || isset( $variant['unit'] );

        return isset( $variant['name'] ) && ! $hasStockIdentity;
    }

    private function mergeSplitAttributeVariants( array $variants, int $fallbackQty = 1 ): array {
        if ( count( $variants ) < 2 ) {
            return array_values( $variants );
        }

        $merged     = [];
        $canMerge   = true;
        $fallbackQty = max( 1, $fallbackQty );

        foreach ( $variants as $variant ) {
            $variant = (array) $variant;

            if ( ! empty( $variant['variant_id'] ) ) {
                $canMerge = false;
                break;
            }

            $attrCount = (int) isset( $variant['color'] )
                + (int) isset( $variant['size'] )
                + (int) isset( $variant['unit'] )
                + (int) isset( $variant['variation'] );

            if ( $attrCount !== 1 ) {
                $canMerge = false;
                break;
            }

            $merged = array_merge( $merged, $variant );
        }

        if ( ! $canMerge || $merged === [] ) {
            return array_values( $variants );
        }

        $merged['qty'] = (int) ( $merged['qty'] ?? $fallbackQty );

        return [$merged];
    }

    private function checkoutVariantHasIdentity( array $variant ): bool {
        if ( $this->isAttributeDefinition( $variant ) ) {
            return false;
        }

        return ! empty( $variant['variant_id'] )
            || ! empty( $this->variantAttributeId( $variant, 'color', 'color_id' ) )
            || ! empty( $this->variantAttributeId( $variant, 'size', 'size_id' ) )
            || ! empty( $this->variantAttributeId( $variant, 'variation', 'variation_id' ) )
            || ! empty( $this->variantAttributeId( $variant, 'unit', 'unit_id' ) );
    }

    private function variantAttributeId( array $variant, string $key, ?string $flatKey = null ): mixed {
        $value = $variant[$key] ?? ( $flatKey ? ( $variant[$flatKey] ?? null ) : null );

        if ( is_array( $value ) ) {
            return $value['id'] ?? null;
        }

        return $value;
    }

    private function normalizeGuestDatas( Request $request ) {
        return collect( $request->input( 'datas', [] ) )->map( function ( $data ) {
            $email = isset( $data['email'] ) ? trim( (string) $data['email'] ) : '';
            $data['email'] = $email !== '' ? $email : 'guest@gmail.com';

            return $data;
        } );
    }

    private function resolveGuestCheckoutEntries( Request $request, $requestDatas, array $shippingTemplate ): array {
        $cartIds = array_values( array_filter( (array) $request->input( 'cart_ids', [] ) ) );

        if ( $cartIds !== [] ) {
            $carts = Cart::query()
                ->whereIn( 'id', $cartIds )
                ->with( ['cartDetails.color', 'cartDetails.size', 'cartDetails.unit'] )
                ->get();

            if ( $carts->isNotEmpty() ) {
                return $carts
                    ->map( fn( Cart $cart ) => [
                        'cart'          => $cart,
                        'tenant_id'     => $cart->tenant_id ?: $request->tenant_id ?: tenant( 'id' ),
                        'datas'         => $requestDatas,
                        'purchase_type' => null,
                    ] )
                    ->all();
            }
        }

        $items = $request->input( 'items', [] );
        if ( is_array( $items ) && $items !== [] ) {
            return collect( $items )->map( function ( $item ) use ( $request, $shippingTemplate ) {
                $variants = $item['variants'] ?? $item['cartItems'] ?? $shippingTemplate['variants'] ?? [];
                $qty      = $item['qty'] ?? $item['product_qty'] ?? $item['quantity'] ?? null;

                return [
                    'cart'          => null,
                    'tenant_id'     => $item['tenant_id'] ?? $request->tenant_id ?? tenant( 'id' ),
                    'datas'         => collect( [array_merge( $shippingTemplate, array_filter( [
                        'id'         => $item['product_id'] ?? $item['id'] ?? null,
                        'product_id' => $item['product_id'] ?? $item['id'] ?? null,
                        'qty'        => $qty,
                        'variants'   => $variants,
                    ], fn( $value ) => $value !== null && $value !== [] ) )] ),
                    'purchase_type' => $item['purchase_type'] ?? null,
                ];
            } )->all();
        }

        $productSpecificDatas = $requestDatas->filter( function ( $data ) {
            return !empty( $data['product_id'] )
                || ( !empty( $data['id'] ) && ( isset( $data['qty'] ) || isset( $data['product_qty'] ) || isset( $data['purchase_type'] ) ) );
        } );

        if ( $productSpecificDatas->count() > 1 ) {
            return $productSpecificDatas->map( function ( $data ) use ( $request, $shippingTemplate ) {
                return [
                    'cart'          => null,
                    'tenant_id'     => $data['tenant_id'] ?? $request->tenant_id ?? tenant( 'id' ),
                    'datas'         => collect( [array_merge( $shippingTemplate, $data )] ),
                    'purchase_type' => $data['purchase_type'] ?? null,
                ];
            } )->all();
        }

        $cart = $request->filled( 'cart_id' )
            ? Cart::with( ['cartDetails.color', 'cartDetails.size', 'cartDetails.unit'] )->find( $request->cart_id )
            : null;

        $explicitProductId = $this->resolveExplicitGuestProductId( $request, $requestDatas );
        if ( $cart && $explicitProductId && (int) $cart->product_id !== (int) $explicitProductId ) {
            $cart = null;
        }

        $tenantId = $cart?->tenant_id ?: $request->tenant_id ?: tenant( 'id' );

        if ( !$tenantId && !$explicitProductId && !$this->resolveGuestProductId( $request, $cart, $tenantId, $requestDatas ) ) {
            return [];
        }

        return [[
            'cart'          => $cart,
            'tenant_id'     => $tenantId,
            'datas'         => $requestDatas,
            'purchase_type' => $request->input( 'purchase_type' ),
        ]];
    }

    private function resolveGuestCheckoutDatasForCart(
        Cart $cart,
        int $requestedCartId,
        $requestDatas,
        array $shippingTemplate
    ): array {
        if ( (int) $cart->id === $requestedCartId && $requestDatas->isNotEmpty() ) {
            return $requestDatas
                ->map( fn( $data ) => $this->mergeCheckoutPayloadWithCart( $cart, $shippingTemplate, (array) $data ) )
                ->values()
                ->all();
        }

        return [$this->buildShippingPayloadForCart( $cart, $shippingTemplate )];
    }

    private function resolveCheckoutDatasForCart(
        Cart $cart,
        int $requestedCartId,
        array $requestDatas,
        array $shippingTemplate
    ): array {

        if ( (int) $cart->id === $requestedCartId && !empty( $requestDatas ) ) {
            return collect( $requestDatas )
                ->map( fn( $data ) => $this->mergeCheckoutPayloadWithCart( $cart, $shippingTemplate, (array) $data ) )
                ->values()
                ->all();
        }

        return [$this->buildShippingPayloadForCart( $cart, $shippingTemplate )];
    }

    private function mergeCheckoutPayloadWithCart( Cart $cart, array $shippingTemplate, array $data ): array {
        $payload = array_merge( $shippingTemplate, $data );

        if ( empty( $payload['variants'] ) ) {
            $built               = $this->buildShippingPayloadForCart( $cart, $shippingTemplate );
            $payload['variants'] = $built['variants'] ?? [];
        }

        if ( empty( $payload['qty'] ) && empty( $payload['product_qty'] ) && empty( $payload['quantity'] ) ) {
            $payload['qty'] = (int) ( $cart->product_qty ?? 0 );
        }

        if ( empty( $payload['delivery_charge'] ) && ! empty( $shippingTemplate['delivery_charge'] ) ) {
            $payload['delivery_charge'] = $shippingTemplate['delivery_charge'];
        }

        return $payload;
    }

    private function resolveAvailableProductQuantity( $tenantId, $product ): int {
        $productQty = (int) ( $product->qty ?? 0 );
        $jsonQty    = (int) collect( $product->variants ?? [] )
            ->map( fn( $variant ) => (array) $variant )
            ->reject( fn( $variant ) => $this->isAttributeDefinition( $variant ) )
            ->sum( fn( $variant ) => (int) ( $variant['qty'] ?? $variant['quantity'] ?? $variant['qnt'] ?? 0 ) );

        $availableQty = max( $productQty, $jsonQty );

        if ( ! $tenantId ) {
            return $availableQty;
        }

        $tenant = Tenant::on( 'mysql' )->find( $tenantId );
        if ( ! $tenant ) {
            return $availableQty;
        }

        try {
            $variants = CrossTenantQueryService::queryTenant(
                $tenant,
                ProductVariant::class,
                fn( $query ) => $query->where( 'product_id', $product->id )
            );

            if ( $variants->isNotEmpty() ) {
                $variantQty = (int) $variants->sum( fn( $variant ) => (int) ( $variant->qty ?? 0 ) );

                return max( $availableQty, $variantQty );
            }
        } catch ( \Throwable $e ) {
            return $availableQty;
        }

        return $availableQty;
    }

    private function resolveVariantAvailableQuantity( $tenantId, $product, array $variant ): int {
        $variantId = (int) ( $variant['variant_id'] ?? 0 );

        if ( $variantId < 1 ) {
            $maybeId = (int) ( $variant['id'] ?? 0 );
            if (
                $maybeId > 0
                && ! $this->isAttributeDefinition( $variant )
                && ( isset( $variant['qty'] ) || isset( $variant['color'] ) || isset( $variant['size'] ) || isset( $variant['unit'] ) )
            ) {
                $variantId = $maybeId;
            }
        }

        if ( $variantId > 0 && $tenantId ) {
            $productVariant = CrossTenantQueryService::getSingleRecordFromTenant(
                $tenantId,
                ProductVariant::class,
                fn( $query ) => $query->where( 'id', $variantId )->where( 'product_id', $product->id )
            );

            if ( $productVariant ) {
                return (int) ( $productVariant->qty ?? 0 );
            }
        }

        $colorId = $this->variantAttributeId( $variant, 'color', 'color_id' );
        $sizeId  = $this->variantAttributeId( $variant, 'size', 'size_id' )
            ?? $this->variantAttributeId( $variant, 'variation', 'variation_id' );
        $unitId  = $this->variantAttributeId( $variant, 'unit', 'unit_id' );

        if ( $tenantId && ( $colorId || $sizeId || $unitId ) ) {
            $productVariant = CrossTenantQueryService::getSingleRecordFromTenant(
                $tenantId,
                ProductVariant::class,
                function ( $query ) use ( $product, $colorId, $sizeId, $unitId ) {
                    $query->where( 'product_id', $product->id );

                    if ( $colorId ) {
                        $query->where( 'color_id', $colorId );
                    }

                    if ( $sizeId ) {
                        $query->where( 'size_id', $sizeId );
                    }

                    if ( $unitId ) {
                        $query->where( 'unit_id', $unitId );
                    }
                }
            );

            if ( $productVariant ) {
                return (int) ( $productVariant->qty ?? 0 );
            }
        }

        return $this->resolveAvailableProductQuantity( $tenantId, $product );
    }

    private function buildShippingPayloadForCart( Cart $cart, array $shippingTemplate ): array {
        if ( !$cart->relationLoaded( 'cartDetails' ) ) {
            $cart->load( ['cartDetails.color', 'cartDetails.size', 'cartDetails.unit'] );
        }

        $variants = $cart->cartDetails
            ->map( function ( $detail ) {
                return array_filter( [
                    'variant_id' => $detail->variant_id,
                    'qty'        => (int) $detail->qty,
                    'color'      => $this->cartDetailColorPayload( $detail ),
                    'size'       => $this->cartDetailSizePayload( $detail ),
                    'unit'       => $this->cartDetailUnitPayload( $detail ),
                ], fn( $value ) => $value !== null );
            } )
            ->filter( fn( $variant ) => (int) ( $variant['qty'] ?? 0 ) > 0 )
            ->values()
            ->all();

        if ( $variants === [] && !empty( $shippingTemplate['variants'] ) ) {
            $variants = $shippingTemplate['variants'];
        }

        if ( $variants === [] && (int) ( $cart->product_qty ?? 0 ) > 0 ) {
            $variants = [['qty' => (int) $cart->product_qty]];
        }

        return array_merge( $shippingTemplate, [
            'variants' => $this->normalizeVariantList( $variants, (int) ( $cart->product_qty ?? 0 ) ?: 1 ),
        ] );
    }

    private function cartDetailColorPayload( $detail ): ?array {
        if ( $detail->relationLoaded( 'color' ) ) {
            $color = $detail->getRelation( 'color' );

            if ( is_object( $color ) ) {
                return [
                    'id'   => $color->id,
                    'name' => $color->name,
                ];
            }
        }

        $colorId = $detail->getRawOriginal( 'color' );

        return $colorId ? ['id' => (int) $colorId] : null;
    }

    private function cartDetailSizePayload( $detail ): ?array {
        if ( $detail->relationLoaded( 'size' ) ) {
            $size = $detail->getRelation( 'size' );

            if ( is_object( $size ) ) {
                return [
                    'id'   => $size->id,
                    'name' => $size->name,
                ];
            }
        }

        $sizeId = $detail->getRawOriginal( 'size' );

        return $sizeId ? ['id' => (int) $sizeId] : null;
    }

    private function cartDetailUnitPayload( $detail ): ?array {
        if ( $detail->relationLoaded( 'unit' ) ) {
            $unit = $detail->getRelation( 'unit' );

            if ( is_object( $unit ) ) {
                return [
                    'id'        => $unit->id,
                    'unit_name' => $unit->unit_name,
                ];
            }
        }

        $unitId = $detail->getRawOriginal( 'unit_id' );

        return $unitId ? ['id' => (int) $unitId] : null;
    }

    private function createGuestCheckoutCart( Request $request, $product, $tenantId, $datas, ?string $purchaseType = null ) {
        $purchaseType = $purchaseType ?: $request->input( 'purchase_type' );
        $sellingType = $product->selling_type ?: 'single';

        if ( !$purchaseType ) {
            $purchaseType = $sellingType === 'bulk' ? 'bulk' : 'single';
        }

        if ( $sellingType === 'bulk' ) {
            $allowedPurchaseTypes = ['bulk'];
        } elseif ( $sellingType === 'both' ) {
            $allowedPurchaseTypes = ['single', 'bulk'];
        } else {
            $allowedPurchaseTypes = ['single'];
        }

        if ( !in_array( $purchaseType, $allowedPurchaseTypes, true ) ) {
            return ['error' => 'Invalid purchase type for guest checkout.'];
        }

        $requestQty = (int) (
            $request->input( 'qty' )
            ?: $request->input( 'product_qty' )
            ?: collect( $request->input( 'items', [] ) )->sum( fn( $item ) => (int) ( $item['qty'] ?? $item['quantity'] ?? $item['product_qty'] ?? 0 ) )
            ?: collect( $request->input( 'cartItems', [] ) )->sum( 'qty' )
            ?: 0
        );

        $totalqty = $this->resolveCheckoutTotalQty(
            $datas->toArray(),
            $requestQty
        );

        if ( $totalqty < 1 ) {
            return ['error' => 'Product quantity not available!'];
        }

        $normalizedDatas = $this->normalizeCheckoutDataVariants( $datas->toArray(), $totalqty );

        $productPrice = $product->discount_price == null ? $product->selling_price : $product->discount_price;
        $affiliateCommission = 0;
        $totalProductPrice = 0;
        $totalAffiliateCommission = 0;
        $advancePayment = 0;
        $totalAdvancePayment = 0;

        if ( $purchaseType === 'single' ) {
            $totalProductPrice = $productPrice * $totalqty;

            if ( $product->single_advance_payment_type == 'percent' ) {
                $advancePayment = ( $productPrice / 100 ) * $product->advance_payment;
            } else {
                $advancePayment = $product->advance_payment;
            }

            $totalAdvancePayment = $advancePayment * $totalqty;
        } else {
            $sellingDetails = collect( $product->selling_details ?: [] );
            $minBulkQty = $sellingDetails->min( 'min_bulk_qty' );

            if ( $minBulkQty && $totalqty < $minBulkQty ) {
                return ['error' => 'Minimum Bulk Quantity ' . $minBulkQty . '.'];
            }

            $bulkdetails = $sellingDetails
                ->filter( function ( $detail ) use ( $totalqty ) {
                    return (int) ( $detail['min_bulk_qty'] ?? 0 ) <= $totalqty;
                } )
                ->sortByDesc( function ( $detail ) {
                    return (int) ( $detail['min_bulk_qty'] ?? 0 );
                } )
                ->first();

            if ( !$bulkdetails ) {
                return ['error' => 'Bulk pricing is not available for this quantity.'];
            }

            $productPrice = $bulkdetails['min_bulk_price'] ?? 0;
            $totalProductPrice = $productPrice * $totalqty;

            if ( ( $bulkdetails['advance_payment_type'] ?? null ) == 'percent' ) {
                $advancePayment = ( $productPrice / 100 ) * ( $bulkdetails['advance_payment'] ?? 0 );
            } else {
                $advancePayment = $bulkdetails['advance_payment'] ?? 0;
            }

            $totalAdvancePayment = $advancePayment * $totalqty;
        }

        $cart = Cart::create( [
            'user_id'                    => 0,
            'product_id'                 => $product->id,
            'product_qty'                => $totalqty,
            'product_price'              => $productPrice,
            'vendor_id'                  => $product->user_id,
            'amount'                     => 0,
            'category_id'                => $product->category_id,
            'totalproductprice'          => $totalProductPrice,
            'total_affiliate_commission' => 0,
            'purchase_type'              => $purchaseType,
            'advancepayment'             => $advancePayment,
            'totaladvancepayment'        => $totalAdvancePayment,
            'tenant_id'                  => $tenantId,
        ] );

        $variantsForDetails = $normalizedDatas[0]['variants'] ?? [['qty' => $totalqty]];
        foreach ( $variantsForDetails as $variant ) {
            CartDetails::create( [
                'cart_id'    => $cart->id,
                'color'      => $this->variantAttributeId( $variant, 'color' ),
                'size'       => $this->variantAttributeId( $variant, 'size' ),
                'qty'        => (int) ( $variant['qty'] ?? $totalqty ),
                'variant_id' => $variant['variant_id'] ?? $variant['id'] ?? null,
                'unit_id'    => $this->variantAttributeId( $variant, 'unit', 'unit_id' ),
            ] );
        }

        return ['cart' => $cart];
    }

    private function flattenCheckoutVariants( $datas ) {
        return collect( $datas )
            ->map( function ( $data ) {
                $variants = is_array( $data ) ? ( $data['variants'] ?? [] ) : ( $data->variants ?? [] );

                if ( ! is_array( $variants ) || $variants === [] ) {
                    return [];
                }

                if ( ! array_is_list( $variants ) ) {
                    if (
                        isset( $variants['variant_id'] )
                        || isset( $variants['product_id'] )
                        || isset( $variants['qty'] )
                        || isset( $variants['color'] )
                        || isset( $variants['size'] )
                    ) {
                        return [$variants];
                    }

                    return [];
                }

                return $variants;
            } )
            ->flatten( 1 )
            ->map( fn( $variant ) => (array) $variant )
            ->reject( fn( $variant ) => $this->isAttributeDefinition( $variant ) )
            ->values();
    }

    private function productIdFromCheckoutVariant( array $variant ): ?int {
        if ( ! empty( $variant['product_id'] ) && (int) $variant['product_id'] > 0 ) {
            return (int) $variant['product_id'];
        }

        $lineId    = (int) ( $variant['id'] ?? 0 );
        $variantId = (int) ( $variant['variant_id'] ?? 0 );

        if ( $lineId < 1 ) {
            return null;
        }

        $looksLikeLineItem = $variantId > 0
            || isset( $variant['qty'] )
            || isset( $variant['quantity'] )
            || isset( $variant['color'] )
            || isset( $variant['size'] )
            || isset( $variant['unit'] );

        if ( ! $looksLikeLineItem ) {
            return null;
        }

        if ( $variantId > 0 && $lineId === $variantId ) {
            return null;
        }

        return $lineId;
    }

    private function resolveExplicitGuestProductId( Request $request, $datas = null ) {
        $candidateKeys = [
            'product_id',
            'product.id',
            'product.product_id',
            'item.product_id',
            'datas.0.product_id',
        ];

        foreach ( $candidateKeys as $key ) {
            $value = $request->input( $key );

            if ( ! empty( $value ) && (int) $value > 0 ) {
                return (int) $value;
            }
        }

        $items = collect( $request->input( 'items', [] ) );
        $itemProductId = $items
            ->map( fn( $item ) => $item['product_id'] ?? null )
            ->filter()
            ->first();

        if ( $itemProductId ) {
            return (int) $itemProductId;
        }

        if ( $datas ) {
            $fromDatas = collect( $datas )
                ->map( fn( $data ) => is_array( $data ) ? ( $data['product_id'] ?? null ) : ( $data->product_id ?? null ) )
                ->filter()
                ->first();

            if ( $fromDatas ) {
                return (int) $fromDatas;
            }

            $fromVariant = $this->flattenCheckoutVariants( $datas )
                ->map( fn( $variant ) => $this->productIdFromCheckoutVariant( $variant ) )
                ->filter()
                ->first();

            if ( $fromVariant ) {
                return (int) $fromVariant;
            }
        }

        return null;
    }

    private function resolveGuestProductId( Request $request, $cart, $tenantId, $datas ) {
        if ( $cart?->product_id ) {
            return $cart->product_id;
        }

        $explicitProductId = $this->resolveExplicitGuestProductId( $request, $datas );
        if ( $explicitProductId ) {
            return $explicitProductId;
        }

        $variantId = $this->flattenCheckoutVariants( $datas )
            ->pluck( 'variant_id' )
            ->filter()
            ->first();

        if ( ! $variantId || ! $tenantId ) {
            return null;
        }

        $variant = CrossTenantQueryService::getSingleRecordFromTenant(
            $tenantId,
            ProductVariant::class,
            function ( $query ) use ( $variantId ) {
                $query->where( 'id', $variantId );
            }
        );

        return $variant?->product_id;
    }

    /**
     * @return array{coupon: ?TenantCoupon, discount: float}|array{error: string}
     */
    private function resolveCheckoutCouponContext(
        ?string $couponCode,
        float $orderAmount,
        int $userId = 0,
        ?string $guestEmail = null
    ): array {
        $couponCode = trim( (string) $couponCode );
        if ( $couponCode === '' ) {
            return ['coupon' => null, 'discount' => 0.0];
        }

        $result = TenantCouponService::validateForCheckout(
            $couponCode,
            $orderAmount,
            $userId > 0 ? $userId : null,
            $guestEmail
        );

        if ( isset( $result['error'] ) ) {
            return ['error' => $result['error']];
        }

        return [
            'coupon'   => $result['coupon'],
            'discount' => (float) $result['discount_amount'],
        ];
    }

    private function computeLineOrderAmount( Cart $cart, array $checkoutDatas, int $totalqty ): float {
        $productAmount  = convertfloat( $cart->product_price ) * convertfloat( $totalqty );
        $first            = $checkoutDatas[0] ?? [];
        $deliveryContext  = ProductCheckoutService::resolveDeliveryCharge( $first );

        return $productAmount + $deliveryContext['charge'];
    }

    private function estimateAuthenticatedCheckoutTotal(
        $carts,
        int $requestedCartId,
        array $requestDatas,
        array $shippingTemplate
    ): float {
        $total = 0.0;

        foreach ( $carts as $cart ) {
            if ( ! $cart->tenant_id ) {
                continue;
            }

            $product = CrossTenantQueryService::getSingleRecordFromTenant(
                $cart->tenant_id,
                Product::class,
                fn( $query ) => $query->where( ['id' => $cart->product_id, 'status' => 'active'] )
            );

            if ( ! $product ) {
                continue;
            }

            $checkoutDatas = $this->resolveCheckoutDatasForCart(
                $cart,
                $requestedCartId,
                $requestDatas,
                $shippingTemplate
            );

            $checkoutDatas = $this->normalizeCheckoutDataVariants(
                $checkoutDatas,
                (int) ( $cart->product_qty ?? 0 )
            );

            $totalqty = $this->resolveCheckoutTotalQty( $checkoutDatas, (int) ( $cart->product_qty ?? 0 ), $cart );
            if ( $this->validateCartForCheckout( $cart, $product, $totalqty, $checkoutDatas, true ) ) {
                continue;
            }

            $total += $this->computeLineOrderAmount( $cart, $checkoutDatas, $totalqty );
        }

        return $total;
    }

    private function estimateGuestCheckoutTotal(
        array $checkoutEntries,
        int $requestedCartId,
        $requestDatas,
        array $shippingTemplate
    ): float {
        $total = 0.0;

        foreach ( $checkoutEntries as $entry ) {
            $cart     = $entry['cart'];
            $tenantId = $entry['tenant_id'];
            $entryDatas = $entry['datas'];

            if ( ! $tenantId ) {
                continue;
            }

            if ( ! $cart ) {
                $productId = $this->resolveGuestProductId( request(), null, $tenantId, $entryDatas );

                if ( ! $productId ) {
                    continue;
                }

                $product = CrossTenantQueryService::getSingleRecordFromTenant(
                    $tenantId,
                    Product::class,
                    fn( $query ) => $query->where( ['id' => $productId, 'status' => 'active'] )
                );

                if ( ! $product ) {
                    continue;
                }

                $checkoutDatas = $this->normalizeCheckoutDataVariants(
                    $entryDatas->toArray(),
                    (int) ( request()->input( 'qty' ) ?: request()->input( 'product_qty' ) ?: 1 )
                );
                $totalqty = $this->resolveCheckoutTotalQty(
                    $checkoutDatas,
                    (int) ( request()->input( 'qty' ) ?: request()->input( 'product_qty' ) ?: 0 )
                );

                if ( $totalqty < 1 ) {
                    continue;
                }

                $productPrice = $product->discount_price == null ? $product->selling_price : $product->discount_price;
                $previewCart  = new Cart( [
                    'product_price' => $productPrice,
                    'product_qty'   => $totalqty,
                ] );

                $total += $this->computeLineOrderAmount( $previewCart, $checkoutDatas, $totalqty );
                continue;
            }

            $product = CrossTenantQueryService::getSingleRecordFromTenant(
                $tenantId,
                Product::class,
                fn( $query ) => $query->where( ['id' => $cart->product_id, 'status' => 'active'] )
            );

            if ( ! $product ) {
                continue;
            }

            $checkoutDatas = $this->resolveGuestCheckoutDatasForCart(
                $cart,
                $requestedCartId,
                $requestDatas,
                $shippingTemplate
            );
            $checkoutDatas = $this->normalizeCheckoutDataVariants(
                $checkoutDatas,
                (int) ( $cart->product_qty ?? 0 )
            );
            $totalqty = $this->resolveCheckoutTotalQty( $checkoutDatas, (int) ( $cart->product_qty ?? 0 ), $cart );

            if ( $this->validateCartForCheckout( $cart, $product, $totalqty, $checkoutDatas, true ) ) {
                continue;
            }

            $total += $this->computeLineOrderAmount( $cart, $checkoutDatas, $totalqty );
        }

        return $total;
    }
}
