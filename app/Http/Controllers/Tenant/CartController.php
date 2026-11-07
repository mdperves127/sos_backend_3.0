<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CrossTenantQueryService;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\ProductAddToCartRequest;
use App\Models\CartDetails;
use App\Models\DeliveryCharge;
use App\Models\ProductDetails;
use App\Models\ProductBundle;
use App\Models\ProductPreOrder;
use App\Service\Vendor\ProductVariantService;
use App\Services\ProductPreOrderService;
use Illuminate\Validation\ValidationException;


class CartController extends Controller
{
    public function addToCart(ProductAddToCartRequest $request)
    {
        $validatedData = $request->validated();


        $getproduct = CrossTenantQueryService::getSingleRecordFromTenant(
            request('tenant_id'),
            Product::class,
            function($query) {
                $query->where('id', request('product_id'))
                    ->where('status', 'active');
            }
        );

        if (!$getproduct) {
            return response()->json([
                'status' => 404,
                'message' => 'Product not found.',
            ], 404);
        }

        $totalqty   = collect( request( 'cartItems' ) )->sum( 'qty' );

        // $totalqty = collect( request( 'qty' ) )->sum();

        $isPreOrder = ProductPreOrderService::isPreOrderProduct( $getproduct );
        $merchantConnection = null;
        if ( request( 'tenant_id' ) ) {
            $merchantTenant = \App\Models\Tenant::on( 'mysql' )->find( request( 'tenant_id' ) );
            if ( $merchantTenant ) {
                $merchantConnection = CrossTenantQueryService::connectionForTenant( $merchantTenant );
            }
        }

        if ( $isPreOrder ) {
            try {
                ProductPreOrderService::assertSlotsAvailable( $getproduct, (int) $totalqty, $merchantConnection );
            } catch ( ValidationException $e ) {
                $msg = collect( $e->errors() )->flatten()->first() ?: 'Pre-order not available';
                return responsejson( $msg, 'fail' );
            }
        } elseif ( request( 'purchase_type' ) == 'single' || ( request( 'purchase_type' ) == 'bulk' && $getproduct->is_connect_bulk_single == 1 ) ) {
            if ( (int) $getproduct->qty < $totalqty ) {
                return responsejson( 'Quantity not available', 'fail' );
            }
        }

        if ( request( 'purchase_type' ) != 'single' ) {
            $min_bulk_qty = min( array_column( $getproduct->selling_details, 'min_bulk_qty' ) );

            if ( $min_bulk_qty > $totalqty ) {
                return responsejson( 'Minimum  Bulk Quantity ' . $min_bulk_qty . '.', 'fail' );
            }
        }

        $user_id       = auth()->id();
        $product_id    = $getproduct->id;
        $productAmount = ProductVariantService::productFallbackPrice( $getproduct );
        $vendor_id     = $getproduct->user_id;

        if ( request( 'purchase_type' ) == 'single' ) {
            $variantTotals = ProductVariantService::lineTotalsFromCartItems(
                $getproduct,
                request( 'cartItems', [] ),
                $merchantConnection
            );
            $product_price     = $variantTotals['product_price'];
            $totalproductprice = $variantTotals['total_product_price'] > 0
                ? $variantTotals['total_product_price']
                : ( $product_price * $totalqty );

            if ( $getproduct->discount_type == 'percent' ) {
                $affi_commission = ( $productAmount / 100 ) * $getproduct->discount_rate;
            } else {
                $affi_commission = $getproduct->discount_rate;
            }
            $total_affiliate_commission = $affi_commission * $totalqty;

            if ( $getproduct->single_advance_payment_type == 'percent' ) {
                $advancepayment = ( $product_price / 100 ) * $getproduct->advance_payment;
            } else {
                $advancepayment = $getproduct->advance_payment;
            }

            $totaladvancepayment = $advancepayment * $totalqty;
        } else {
            $bulkdetails       = collect( $getproduct->selling_details )->where( 'min_bulk_qty', '<=', $totalqty )->max();
            $product_price     = $bulkdetails['min_bulk_price'];
            $totalproductprice = $product_price * $totalqty;

            if ( $bulkdetails['bulk_commission_type'] == 'percent' ) {
                $bulk_commission = ( $product_price / 100 ) * $bulkdetails['bulk_commission'];
            } else {
                $bulk_commission = $bulkdetails['bulk_commission'];
            }

            $total_affiliate_commission = $bulk_commission * $totalqty;
            $affi_commission            = $bulk_commission;

            if ( $bulkdetails['advance_payment_type'] == 'percent' ) {
                $advancepayment = ( $product_price / 100 ) * $bulkdetails['advance_payment'];
            } else {
                $advancepayment = $bulkdetails['advance_payment'];
            }

            $totaladvancepayment = $advancepayment * $totalqty;
        }

        $preOrderPaymentType = null;
        if ( $isPreOrder ) {
            $settings = null;
            if ( $merchantConnection ) {
                $settings = ProductPreOrder::on( $merchantConnection )
                    ->where( 'product_id', $getproduct->id )
                    ->where( 'status', 'active' )
                    ->first();
            }
            if ( ! $settings ) {
                $settings = ProductPreOrderService::activeSettingsForProduct( (int) $getproduct->id );
            }

            if ( ! $settings ) {
                return responsejson( 'Pre-order is not available for this product.', 'fail' );
            }

            try {
                $resolved = ProductPreOrderService::resolvePayment(
                    $getproduct,
                    $settings,
                    (string) request( 'pre_order_payment', request( 'pre_order_payment_type', 'advance' ) ),
                    (float) $product_price
                );
            } catch ( ValidationException $e ) {
                $msg = collect( $e->errors() )->flatten()->first() ?: 'Invalid pre-order payment.';
                return responsejson( $msg, 'fail' );
            }

            $advancepayment      = $resolved['advance_payment'];
            $totaladvancepayment = $advancepayment * $totalqty;
            $preOrderPaymentType = $resolved['payment_type'];
        }

        $incomingVariantIds = collect( request( 'cartItems', [] ) )
            ->map( function ( $item ) {
                $variantId = is_array( $item ) ? ( $item['variant_id'] ?? null ) : null;

                return $variantId === null || $variantId === '' ? null : (int) $variantId;
            } )
            ->filter( fn ( $id ) => $id && $id > 0 )
            ->unique()
            ->values();

        $existingCartQuery = Cart::query()
            ->where( 'product_id', $product_id )
            ->where( 'user_id', $user_id );

        if ( $incomingVariantIds->isNotEmpty() ) {
            // Allow same product with a different variant; block only matching variant lines.
            $alreadyInCart = ( clone $existingCartQuery )
                ->whereHas( 'cartDetails', function ( $query ) use ( $incomingVariantIds ) {
                    $query->whereIn( 'variant_id', $incomingVariantIds->all() );
                } )
                ->exists();
        } else {
            // Non-variant product: keep unique product-in-cart behavior.
            $alreadyInCart = $existingCartQuery->exists();
        }

        if ( $alreadyInCart ) {
            return response()->json( [
                'status'  => 409,
                'message' => $getproduct->name . ' already added to cart.',
            ] );
        }

        $obj = collect( request()->all() );

        DB::beginTransaction();

        // try {
            // Save cart in tenant DB and get created instance
            $cartitem = CrossTenantQueryService::saveToTenant( tenant()->id, Cart::class, function ( $cart ) use (
                $user_id,
                $product_id,
                $product_price,
                $vendor_id,
                $affi_commission,
                $getproduct,
                $totalqty,
                $totalproductprice,
                $total_affiliate_commission,
                $advancepayment,
                $totaladvancepayment,
                $preOrderPaymentType
            ) {
                $cart->user_id                    = $user_id;
                $cart->product_id                 = $product_id;
                $cart->product_price              = $product_price;
                $cart->vendor_id                  = $vendor_id;
                $cart->amount                     = $affi_commission;
                $cart->category_id                = $getproduct->category_id;
                $cart->product_qty                = $totalqty;
                $cart->totalproductprice          = $totalproductprice;
                $cart->total_affiliate_commission = $total_affiliate_commission;
                $cart->purchase_type              = request( 'purchase_type' );
                $cart->advancepayment             = $advancepayment;
                $cart->totaladvancepayment        = $totaladvancepayment;
                $cart->tenant_id                  = request('tenant_id');
                $cart->pre_order_payment_type     = $preOrderPaymentType;
            } );

            if ( !$cartitem ) {
                throw new \RuntimeException('Failed to create cart in tenant database');
            }

            foreach ( request( 'cartItems' ) as $data ) {
                $colors[]   = $data['color'] ?? null;
                $sizes[]    = $data['size'] ?? null;
                $qnts[]     = $data['qty'] ?? 1;
                $variants[] = $data['variant_id'] ?? null;
                $units[]    = $data['unit'] ?? null;
            }

            foreach ( $qnts as $key => $value ) {
                CrossTenantQueryService::saveToTenant( tenant()->id, CartDetails::class, function ( $cartDetail ) use (
                    $cartitem,
                    $colors,
                    $sizes,
                    $variants,
                    $units,
                    $key,
                    $value
                ) {
                    $cartDetail->cart_id    = $cartitem->id;
                    $cartDetail->color      = $colors[$key];
                    $cartDetail->size       = $sizes[$key];
                    $cartDetail->qty        = $value;
                    $cartDetail->variant_id = $variants[$key];
                    $cartDetail->unit_id    = $units[$key];
                } );
            }

            DB::commit();

        // } catch ( \Exception $e ) {
        //     DB::rollBack();
        //     return response()->json( ['error' => 'Failed to create cart.'], 500 );
        // }

        return response()->json( [
            'status'  => 201,
            'message' => 'Added to Cart',
        ] );
    }

    /**
     * Add an active product bundle to the storefront cart (merchant tenants only).
     */
    public function addBundleToCart( Request $request )
    {
        if ( ! function_exists( 'tenant' ) || ! tenant() || tenant( 'type' ) !== 'merchant' ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Bundles are only available on merchant storefronts.',
            ], 403 );
        }

        $request->validate( [
            'bundle_id' => ['required', 'integer', 'min:1'],
            'qty'       => ['nullable', 'integer', 'min:1'],
        ] );

        $qty = max( 1, (int) $request->input( 'qty', 1 ) );
        $bundle = ProductBundle::active()
            ->with( 'items' )
            ->find( (int) $request->bundle_id );

        if ( ! $bundle || $bundle->items->count() < 2 ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Bundle not found.',
            ], 404 );
        }

        $userId = auth()->id();
        if ( Cart::where( 'user_id', $userId )->where( 'bundle_id', $bundle->id )->exists() ) {
            return response()->json( [
                'status'  => 409,
                'message' => $bundle->name . ' already added to cart.',
            ] );
        }

        $firstProductId = (int) $bundle->items->first()->product_id;
        $unitPrice      = (float) $bundle->bundle_price;

        $cart = Cart::create( [
            'user_id'                    => $userId,
            'product_id'                 => $firstProductId,
            'bundle_id'                  => $bundle->id,
            'product_price'              => $unitPrice,
            'vendor_id'                  => vendorId(),
            'amount'                     => 0,
            'category_id'                => $bundle->category_id,
            'product_qty'                => $qty,
            'totalproductprice'          => $unitPrice * $qty,
            'total_affiliate_commission' => 0,
            'purchase_type'              => 'single',
            'advancepayment'             => 0,
            'totaladvancepayment'        => 0,
            'tenant_id'                  => tenant()->id,
        ] );

        CartDetails::create( [
            'cart_id' => $cart->id,
            'qty'     => $qty,
        ] );

        return response()->json( [
            'status'  => 201,
            'message' => 'Bundle added to Cart',
            'cart_id' => $cart->id,
        ] );
    }

    public function cart(Request $request)
    {
        $cart = Cart::where( 'user_id', auth()->id() )
            ->with( [
                'cartDetails.color',
                'cartDetails.size',
                'cartDetails.unit',
                'cartDetails.variant',
                'bundle.items.product:id,name,image,sku',
            ] )
            ->get();

        if ( $this->isDropshipperStorefront() ) {
            $cart = $this->enrichDropshipperCartItems( $cart );
        } else {
            $cart->load( 'product' );
        }

        $cart = $this->enrichCartVariantData( $cart );

        $deliveryCharge = DeliveryCharge::where('status', 'active')->select('id', 'area', 'charge')->get();
        return response()->json(
            [
                'message' => 'Cart fetched successfully',
                'success' => true,
                'cart' => $cart,
                'deliveryCharge' => $deliveryCharge,
            ]
        );
    }
    public function deleteCart(Request $request)
    {
        $cart = Cart::find($request->id)->delete();
        return response()->json([
            'message' => 'Cart deleted successfully',
            'success' => true,
        ]);
    }

    public function updateQuantity( Request $request, $id )
    {
        $request->validate( [
            'qty'               => 'nullable|integer|min:1',
            'cartItems'         => 'nullable|array',
            'cartItems.*.id'    => 'required_with:cartItems|integer',
            'cartItems.*.qty'   => 'required_with:cartItems|integer|min:1',
        ] );

        if ( ! $request->filled( 'qty' ) && ! $request->filled( 'cartItems' ) ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Quantity or cartItems is required.',
            ], 422 );
        }

        $cart = Cart::where( 'user_id', auth()->id() )
            ->with( 'cartDetails' )
            ->find( $id );

        if ( ! $cart ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Cart not found.',
            ], 404 );
        }

        if ( ! $cart->tenant_id ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Cart is missing tenant information.',
            ], 400 );
        }

        $product = CrossTenantQueryService::getSingleRecordFromTenant(
            $cart->tenant_id,
            Product::class,
            fn( $query ) => $query->where( 'id', $cart->product_id )->where( 'status', 'active' )
        );

        if ( ! $product ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product not found or unavailable.',
            ], 404 );
        }

        if ( $request->filled( 'cartItems' ) ) {
            foreach ( $request->input( 'cartItems' ) as $item ) {
                $detail = $cart->cartDetails->firstWhere( 'id', (int) $item['id'] );

                if ( ! $detail ) {
                    return response()->json( [
                        'status'  => 404,
                        'message' => 'Cart item not found.',
                    ], 404 );
                }

                $detail->qty = (int) $item['qty'];
                $detail->save();
            }
        } else {
            $qty = (int) $request->input( 'qty' );

            if ( $cart->cartDetails->count() === 1 ) {
                $cart->cartDetails->first()->update( ['qty' => $qty] );
            } elseif ( $cart->cartDetails->isEmpty() ) {
                CartDetails::create( [
                    'cart_id' => $cart->id,
                    'qty'     => $qty,
                ] );
            } else {
                return response()->json( [
                    'status'  => 422,
                    'message' => 'Use cartItems to update quantity for multi-variant products.',
                ], 422 );
            }
        }

        $cart->load( 'cartDetails' );
        $totalqty = (int) $cart->cartDetails->sum( 'qty' );

        if ( $totalqty < 1 ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Quantity must be at least 1.',
            ], 422 );
        }

        if ( ProductPreOrderService::isPreOrderProduct( $product ) ) {
            try {
                $conn = null;
                $merchantTenant = \App\Models\Tenant::on( 'mysql' )->find( $cart->tenant_id );
                if ( $merchantTenant ) {
                    $conn = CrossTenantQueryService::connectionForTenant( $merchantTenant );
                }
                ProductPreOrderService::assertSlotsAvailable( $product, $totalqty, $conn );
            } catch ( ValidationException $e ) {
                $msg = collect( $e->errors() )->flatten()->first() ?: 'Pre-order not available';
                return responsejson( $msg, 'fail' );
            }
        } elseif ( $cart->purchase_type === 'single' || ( $cart->purchase_type === 'bulk' && $product->is_connect_bulk_single == 1 ) ) {
            if ( (int) $product->qty < $totalqty ) {
                return responsejson( 'Quantity not available', 'fail' );
            }
        }

        if ( $cart->purchase_type !== 'single' ) {
            $sellingDetails = is_array( $product->selling_details )
                ? $product->selling_details
                : ( json_decode( $product->selling_details ?? '[]', true ) ?: [] );

            if ( $sellingDetails !== [] ) {
                $minBulkQty = min( array_column( $sellingDetails, 'min_bulk_qty' ) );

                if ( $minBulkQty > $totalqty ) {
                    return responsejson( 'Minimum  Bulk Quantity ' . $minBulkQty . '.', 'fail' );
                }
            }
        }

        $merchantConnection = null;
        if ( $cart->tenant_id ) {
            $merchantTenant = \App\Models\Tenant::on( 'mysql' )->find( $cart->tenant_id );
            if ( $merchantTenant ) {
                $merchantConnection = CrossTenantQueryService::connectionForTenant( $merchantTenant );
            }
        }

        try {
            $pricing = $this->calculateCartPricing(
                $product,
                $cart->purchase_type,
                $totalqty,
                $cart->cartDetails,
                $merchantConnection
            );
        } catch ( \RuntimeException $e ) {
            return response()->json( [
                'status'  => 422,
                'message' => $e->getMessage(),
            ], 422 );
        }

        $cart->update( [
            'product_qty'                => $totalqty,
            'product_price'              => $pricing['product_price'],
            'amount'                     => $pricing['affiliate_commission'],
            'totalproductprice'          => $pricing['total_product_price'],
            'total_affiliate_commission' => $pricing['total_affiliate_commission'],
            'advancepayment'             => $pricing['advance_payment'],
            'totaladvancepayment'        => $pricing['total_advance_payment'],
        ] );

        $cart->load( ['cartDetails.color', 'cartDetails.size', 'cartDetails.unit', 'cartDetails.variant', 'product'] );

        if ( $this->isDropshipperStorefront() ) {
            $cart = $this->enrichDropshipperCartItems( collect( [$cart] ) )->first();
        }

        $cart = $this->enrichCartVariantData( collect( [$cart] ) )->first();

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Cart quantity updated successfully.',
            'cart'    => $cart,
        ] );
    }

    /**
     * @return array{
     *     product_price: float,
     *     affiliate_commission: float,
     *     total_product_price: float,
     *     total_affiliate_commission: float,
     *     advance_payment: float,
     *     total_advance_payment: float
     * }
     */
    private function calculateCartPricing(
        object $product,
        string $purchaseType,
        int $totalqty,
        $cartDetails = null,
        ?string $merchantConnection = null
    ): array
    {
        $productAmount = ProductVariantService::productFallbackPrice( $product );

        if ( $purchaseType === 'single' ) {
            $productPrice      = $productAmount;
            $totalProductPrice = $productPrice * $totalqty;

            if ( $cartDetails !== null ) {
                $variantTotals = ProductVariantService::lineTotalsFromCartItems(
                    $product,
                    collect( $cartDetails )->map( fn ( $detail ) => [
                        'variant_id' => $detail->variant_id ?? null,
                        'qty'        => $detail->qty ?? 0,
                    ] )->all(),
                    $merchantConnection
                );

                if ( $variantTotals['total_qty'] > 0 ) {
                    $productPrice      = $variantTotals['product_price'];
                    $totalProductPrice = $variantTotals['total_product_price'];
                }
            }

            if ( $product->discount_type == 'percent' ) {
                $affiliateCommission = ( $productAmount / 100 ) * $product->discount_rate;
            } else {
                $affiliateCommission = $product->discount_rate;
            }

            if ( $product->single_advance_payment_type == 'percent' ) {
                $advancePayment = ( $productPrice / 100 ) * $product->advance_payment;
            } else {
                $advancePayment = $product->advance_payment;
            }

            return [
                'product_price'              => $productPrice,
                'affiliate_commission'       => $affiliateCommission,
                'total_product_price'        => $totalProductPrice,
                'total_affiliate_commission' => $affiliateCommission * $totalqty,
                'advance_payment'            => $advancePayment,
                'total_advance_payment'      => $advancePayment * $totalqty,
            ];
        }

        $sellingDetails = is_array( $product->selling_details )
            ? $product->selling_details
            : ( json_decode( $product->selling_details ?? '[]', true ) ?: [] );

        $bulkdetails = collect( $sellingDetails )
            ->filter( fn( $detail ) => (int) ( $detail['min_bulk_qty'] ?? 0 ) <= $totalqty )
            ->sortByDesc( fn( $detail ) => (int) ( $detail['min_bulk_qty'] ?? 0 ) )
            ->first();

        if ( ! $bulkdetails ) {
            throw new \RuntimeException( 'Bulk pricing is not available for this quantity.' );
        }

        $productPrice = $bulkdetails['min_bulk_price'] ?? 0;

        if ( ( $bulkdetails['bulk_commission_type'] ?? null ) == 'percent' ) {
            $affiliateCommission = ( $productPrice / 100 ) * ( $bulkdetails['bulk_commission'] ?? 0 );
        } else {
            $affiliateCommission = $bulkdetails['bulk_commission'] ?? 0;
        }

        if ( ( $bulkdetails['advance_payment_type'] ?? null ) == 'percent' ) {
            $advancePayment = ( $productPrice / 100 ) * ( $bulkdetails['advance_payment'] ?? 0 );
        } else {
            $advancePayment = $bulkdetails['advance_payment'] ?? 0;
        }

        return [
            'product_price'              => $productPrice,
            'affiliate_commission'       => $affiliateCommission,
            'total_product_price'        => $productPrice * $totalqty,
            'total_affiliate_commission' => $affiliateCommission * $totalqty,
            'advance_payment'            => $advancePayment,
            'total_advance_payment'      => $advancePayment * $totalqty,
        ];
    }

    private function isDropshipperStorefront(): bool {
        return function_exists( 'tenant' ) && tenant() && tenant()->type === 'dropshipper';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Cart>  $cartItems
     * @return \Illuminate\Support\Collection<int, \App\Models\Cart>
     */
    private function enrichDropshipperCartItems( $cartItems ) {
        return $cartItems->map( function ( Cart $cartItem ) {
            $productDetailQuery = ProductDetails::query()
                ->where( 'product_id', $cartItem->product_id )
                ->where( 'status', 1 );

            if ( $cartItem->tenant_id ) {
                $productDetailQuery->where( 'tenant_id', $cartItem->tenant_id );
            }

            $productDetail = $productDetailQuery->first();

            $cartItem->profit_amount                 = $productDetail?->profit_amount ?? 0;
            $cartItem->total_profit_amount           = round( (float) ( $productDetail?->profit_amount ?? 0 ) * (int) $cartItem->product_qty, 2 );
            $cartItem->dropshipper_product_detail_id = $productDetail?->id;
            $cartItem->dropshipper_uniqid            = $productDetail?->uniqid;

            if ( $cartItem->tenant_id ) {
                $merchantProduct = CrossTenantQueryService::getSingleRecordFromTenant(
                    $cartItem->tenant_id,
                    Product::class,
                    fn ( $query ) => $query->where( 'id', $cartItem->product_id )->where( 'status', 'active' )
                );

                if ( $merchantProduct ) {
                    $cartItem->unsetRelation( 'product' );
                    $cartItem->setAttribute( 'product', $merchantProduct );
                }
            }

            return $cartItem;
        } );
    }

    /**
     * Attach storefront-friendly variant payloads on each cart line.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Cart>  $cartItems
     * @return \Illuminate\Support\Collection<int, \App\Models\Cart>
     */
    private function enrichCartVariantData( $cartItems ) {
        return $cartItems->map( function ( Cart $cartItem ) {
            $product      = $cartItem->product;
            $variantsJson = is_array( $product?->variants ) ? $product->variants : [];
            $byId         = [];

            foreach ( $variantsJson as $jsonVariant ) {
                $jsonVariant = (array) $jsonVariant;
                $id          = (int) ( $jsonVariant['variant_id'] ?? $jsonVariant['id'] ?? 0 );
                if ( $id > 0 ) {
                    $byId[$id] = $jsonVariant;
                }
            }

            if ( $product ) {
                $product->setAttribute( 'has_variants', $variantsJson !== [] ? 1 : 0 );
            }

            $fallback = $product ? ProductVariantService::productFallbackPrice( $product ) : 0.0;

            $cartItem->cartDetails->each( function ( CartDetails $detail ) use ( $byId, $fallback ) {
                $variantId = (int) ( $detail->variant_id ?? 0 );

                if ( $variantId <= 0 ) {
                    $detail->setAttribute( 'variant', null );
                    $detail->setAttribute( 'variant_label', null );
                    $detail->setAttribute( 'variant_options', [] );

                    return;
                }

                $json = $byId[$variantId] ?? null;
                $rate = $json ? ProductVariantService::resolveRateFromPayload( $json ) : 0.0;

                if ( $rate <= 0 && $detail->relationLoaded( 'variant' ) && $detail->variant ) {
                    $rate = (float) ( $detail->variant->rate ?? 0 );
                }

                $payload = [
                    'id'              => $variantId,
                    'variant_id'      => $variantId,
                    'key'             => $json['key'] ?? null,
                    'label'           => $json['label'] ?? null,
                    'sku'             => $json['sku'] ?? null,
                    'image'           => $json['image'] ?? null,
                    'qty'             => isset( $json['qty'] )
                        ? max( 0, (int) $json['qty'] )
                        : ( $detail->relationLoaded( 'variant' ) && $detail->variant
                            ? max( 0, (int) $detail->variant->qty )
                            : null ),
                    'options'         => array_values( (array) ( $json['options'] ?? [] ) ),
                    'unit_id'         => $json['unit_id'] ?? $detail->unit_id,
                    'size_id'         => $json['size_id'] ?? $detail->size,
                    'color_id'        => $json['color_id'] ?? $detail->color,
                    'rate'            => $rate,
                    'price'           => $rate,
                    'effective_price' => $rate > 0 ? $rate : $fallback,
                ];

                $detail->setAttribute( 'variant', $payload );
                $detail->setAttribute( 'variant_label', $payload['label'] );
                $detail->setAttribute( 'variant_key', $payload['key'] );
                $detail->setAttribute( 'variant_options', $payload['options'] );
                $detail->setAttribute( 'variant_image', $payload['image'] );
                $detail->setAttribute( 'effective_price', $payload['effective_price'] );
            } );

            return $cartItem;
        } );
    }
}
