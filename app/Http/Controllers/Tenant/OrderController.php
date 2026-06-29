<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CrossTenantQueryService;
use App\Enums\Status;
use App\Http\Requests\ProductRequest;
use App\Services\ProductCheckoutService;

class OrderController extends Controller
{
    function guestStore( Request $request ) {
        $requestDatas = $this->normalizeGuestDatas( $request );

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
        $placed = 0;
        $failed = [];

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

            if ( !$cart ) {
                $productId = $this->resolveGuestProductId( $request, null, $tenantId, $entryDatas );

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

            $totalqty = $this->resolveCheckoutTotalQty( $checkoutDatas, $cart );

            $validationError = $this->validateCartForCheckout( $cart, $product, $totalqty, $checkoutDatas );
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
                $tenantId
            );

            $payload = method_exists( $response, 'getContent' )
                ? json_decode( $response->getContent(), true )
                : null;

            if ( ( $payload['status'] ?? null ) === 200 ) {
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
            'failed'        => $failed,
        ] );
    }

    function store( ProductRequest $request ) {
        $user = auth()->user();
        $requestDatas = $request->input( 'datas', [] );
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

        $placed = 0;
        $failed = [];

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

            $totalqty = $this->resolveCheckoutTotalQty( $checkoutDatas, $cart );

            $validationError = $this->validateCartForCheckout( $cart, $product, $totalqty, $checkoutDatas );
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
                $cart->tenant_id
            );

            $payload = method_exists( $response, 'getContent' )
                ? json_decode( $response->getContent(), true )
                : null;

            if ( ( $payload['status'] ?? null ) === 200 ) {
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
            'failed'        => $failed,
        ] );
    }

    private function validateCartForCheckout( Cart $cart, $product, int $totalqty, array $checkoutDatas = [] ): ?string {
        if ( $cart->purchase_type == 'single' && $product->selling_type == 'bulk' ) {
            return 'Something is wrong delete the cart.';
        }

        if ( $cart->purchase_type == 'single' || $product->is_connect_bulk_single == 1 ) {
            $variantLines = $this->extractCheckoutVariants( $checkoutDatas )
                ->filter( fn ( $variant ) => !empty( $variant['variant_id'] ) && (int) ( $variant['qty'] ?? 0 ) > 0 );

            if ( $variantLines->isNotEmpty() ) {
                foreach ( $variantLines as $variant ) {
                    $requestedQty = (int) $variant['qty'];
                    $productVariant = CrossTenantQueryService::getSingleRecordFromTenant(
                        $cart->tenant_id,
                        ProductVariant::class,
                        fn ( $query ) => $query->where( 'id', $variant['variant_id'] )
                            ->where( 'product_id', $product->id )
                    );

                    if ( !$productVariant || (int) $productVariant->qty < $requestedQty ) {
                        return 'Product quantity not available!';
                    }
                }
            } else {
                $availableQty = $this->resolveAvailableProductQuantity( $cart->tenant_id, $product );
                if ( $availableQty < $totalqty ) {
                    return 'Product quantity not available!';
                }
            }
        }

        if ( $product->status == Status::Pending->value ) {
            return 'The product under construction!';
        }

        return null;
    }

    private function resolveCheckoutTotalQty( array $checkoutDatas, ?Cart $cart = null ): int {
        $totalqty = (int) $this->extractCheckoutVariants( $checkoutDatas )->sum( 'qty' );

        if ( $totalqty < 1 && $cart ) {
            $totalqty = (int) ( $cart->product_qty ?? 0 );
        }

        if ( $totalqty < 1 && $cart ) {
            if ( !$cart->relationLoaded( 'cartDetails' ) ) {
                $cart->load( 'cartDetails' );
            }

            $totalqty = (int) $cart->cartDetails->sum( 'qty' );
        }

        return $totalqty;
    }

    private function extractCheckoutVariants( array $checkoutDatas ) {
        return collect( $checkoutDatas )
            ->pluck( 'variants' )
            ->filter()
            ->flatMap( fn ( $variants ) => is_array( $variants ) ? $variants : [] )
            ->values();
    }

    private function mergeCheckoutPayloadWithCart( Cart $cart, array $shippingTemplate, array $data ): array {
        $payload = array_merge( $shippingTemplate, $data );

        if ( empty( $payload['variants'] ) ) {
            $built               = $this->buildShippingPayloadForCart( $cart, $shippingTemplate );
            $payload['variants'] = $built['variants'] ?? [];
        }

        return $payload;
    }

    private function resolveAvailableProductQuantity( $tenantId, $product ): int {
        if ( !$tenantId ) {
            return (int) ( $product->qty ?? 0 );
        }

        $tenant = \App\Models\Tenant::on( 'mysql' )->find( $tenantId );
        if ( !$tenant ) {
            return (int) ( $product->qty ?? 0 );
        }

        $variants = CrossTenantQueryService::queryTenant(
            $tenant,
            ProductVariant::class,
            fn ( $query ) => $query->where( 'product_id', $product->id )
        );

        if ( $variants->isNotEmpty() ) {
            return (int) collect( $variants )->sum( fn ( $variant ) => (int) ( $variant->qty ?? 0 ) );
        }

        return (int) ( $product->qty ?? 0 );
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
            return Cart::query()
                ->whereIn( 'id', $cartIds )
                ->with( ['cartDetails.color', 'cartDetails.size', 'cartDetails.unit'] )
                ->get()
                ->map( fn( Cart $cart ) => [
                    'cart'          => $cart,
                    'tenant_id'     => $cart->tenant_id ?: $request->tenant_id ?: tenant( 'id' ),
                    'datas'         => $requestDatas,
                    'purchase_type' => null,
                ] )
                ->all();
        }

        $items = $request->input( 'items', [] );
        if ( is_array( $items ) && $items !== [] ) {
            return collect( $items )->map( function ( $item ) use ( $request, $shippingTemplate ) {
                $variants = $item['variants'] ?? $item['cartItems'] ?? $shippingTemplate['variants'] ?? [];

                return [
                    'cart'          => null,
                    'tenant_id'     => $item['tenant_id'] ?? $request->tenant_id ?? tenant( 'id' ),
                    'datas'         => collect( [array_merge( $shippingTemplate, array_filter( [
                        'id'         => $item['product_id'] ?? $item['id'] ?? null,
                        'product_id' => $item['product_id'] ?? $item['id'] ?? null,
                        'variants'   => $variants,
                    ], fn( $value ) => $value !== null ) )] ),
                    'purchase_type' => $item['purchase_type'] ?? null,
                ];
            } )->all();
        }

        $productSpecificDatas = $requestDatas->filter( function ( $data ) {
            return !empty( $data['id'] )
                || !empty( $data['product_id'] )
                || !empty( $data['variants'] );
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
        $tenantId = $cart?->tenant_id ?: $request->tenant_id ?: tenant( 'id' );

        if ( !$tenantId && !$this->resolveGuestProductId( $request, $cart, $tenantId, $requestDatas ) ) {
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
                ->map( fn ( $data ) => $this->mergeCheckoutPayloadWithCart( $cart, $shippingTemplate, $data ) )
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
                ->map( fn ( $data ) => $this->mergeCheckoutPayloadWithCart( $cart, $shippingTemplate, $data ) )
                ->values()
                ->all();
        }

        return [$this->buildShippingPayloadForCart( $cart, $shippingTemplate )];
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

        return array_merge( $shippingTemplate, [
            'variants' => $variants,
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

        $totalqty = (int) $datas->pluck( 'variants' )->collapse()->sum( 'qty' );

        if ( $totalqty < 1 ) {
            $totalqty = (int) collect( $request->input( 'cartItems', [] ) )->sum( 'qty' );
        }

        if ( $totalqty < 1 ) {
            $totalqty = (int) $request->input( 'qty', 0 );
        }

        if ( $totalqty < 1 ) {
            return ['error' => 'Product quantity not available!'];
        }

        $checkoutDatas = $datas->values()->all();
        if ( $this->extractCheckoutVariants( $checkoutDatas )->isEmpty() && is_array( $request->input( 'cartItems' ) ) ) {
            $firstPayload = $checkoutDatas[0] ?? $datas->first() ?? [];
            $firstPayload['variants'] = collect( $request->input( 'cartItems', [] ) )
                ->map( fn ( $item ) => array_filter( [
                    'variant_id' => $item['variant_id'] ?? null,
                    'qty'        => (int) ( $item['qty'] ?? 0 ),
                    'color'      => $item['color'] ?? null,
                    'size'       => $item['size'] ?? null,
                    'unit'       => $item['unit'] ?? null,
                ], fn ( $value ) => $value !== null ) )
                ->values()
                ->all();
            $checkoutDatas = [$firstPayload];
            $totalqty       = $this->resolveCheckoutTotalQty( $checkoutDatas );
        }

        $previewCart   = new Cart( [
            'purchase_type' => $purchaseType,
            'tenant_id'     => $tenantId,
        ] );
        $validationError = $this->validateCartForCheckout( $previewCart, $product, $totalqty, $checkoutDatas );

        if ( $validationError ) {
            return ['error' => $validationError];
        }

        $productPrice = $product->discount_price == null ? $product->selling_price : $product->discount_price;
        $affiliateCommission = 0;
        $totalProductPrice = 0;
        $totalAffiliateCommission = 0;
        $advancePayment = 0;
        $totalAdvancePayment = 0;

        if ( $purchaseType === 'single' ) {
            if ( $product->discount_type == 'percent' ) {
                $affiliateCommission = ( $productPrice / 100 ) * $product->discount_rate;
            } else {
                $affiliateCommission = $product->discount_rate;
            }

            $totalProductPrice = $productPrice * $totalqty;
            $totalAffiliateCommission = $affiliateCommission * $totalqty;

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

            if ( ( $bulkdetails['bulk_commission_type'] ?? null ) == 'percent' ) {
                $affiliateCommission = ( $productPrice / 100 ) * ( $bulkdetails['bulk_commission'] ?? 0 );
            } else {
                $affiliateCommission = $bulkdetails['bulk_commission'] ?? 0;
            }

            $totalAffiliateCommission = $affiliateCommission * $totalqty;

            if ( ( $bulkdetails['advance_payment_type'] ?? null ) == 'percent' ) {
                $advancePayment = ( $productPrice / 100 ) * ( $bulkdetails['advance_payment'] ?? 0 );
            } else {
                $advancePayment = $bulkdetails['advance_payment'] ?? 0;
            }

            $totalAdvancePayment = $advancePayment * $totalqty;
        }

        $cart = Cart::create( [
            'user_id'                    => 1,
            'product_id'                 => $product->id,
            'product_qty'                => $totalqty,
            'product_price'              => $productPrice,
            'vendor_id'                  => $product->user_id,
            'amount'                     => $affiliateCommission,
            'category_id'                => $product->category_id,
            'totalproductprice'          => $totalProductPrice,
            'total_affiliate_commission' => $totalAffiliateCommission,
            'purchase_type'              => $purchaseType,
            'advancepayment'             => $advancePayment,
            'totaladvancepayment'        => $totalAdvancePayment,
            'tenant_id'                  => $tenantId,
        ] );

        return ['cart' => $cart];
    }

    private function resolveGuestProductId( Request $request, $cart, $tenantId, $datas ) {
        if ( $cart?->product_id ) {
            return $cart->product_id;
        }

        $candidateKeys = [
            'product_id',
            'id',
            'product.id',
            'product.product_id',
            'item.id',
            'item.product_id',
            'datas.0.id',
            'datas.0.product_id',
        ];

        foreach ( $candidateKeys as $key ) {
            $value = $request->input( $key );

            if ( !empty( $value ) ) {
                return $value;
            }
        }

        $variantProductId = $datas
            ->pluck( 'id' )
            ->filter()
            ->first();

        if ( $variantProductId ) {
            return $variantProductId;
        }

        $variantProductId = $datas
            ->pluck( 'product_id' )
            ->filter()
            ->first();

        if ( $variantProductId ) {
            return $variantProductId;
        }

        $variantProductId = $datas
            ->pluck( 'variants' )
            ->collapse()
            ->pluck( 'product_id' )
            ->filter()
            ->first();

        if ( $variantProductId ) {
            return $variantProductId;
        }

        $variantId = $datas
            ->pluck( 'variants' )
            ->collapse()
            ->pluck( 'variant_id' )
            ->filter()
            ->first();

        if ( !$variantId ) {
            $variantId = $datas
                ->pluck( 'variants' )
                ->collapse()
                ->pluck( 'id' )
                ->filter()
                ->first();
        }

        if ( !$variantId ) {
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
}
