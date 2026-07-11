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

        if ( request( 'purchase_type' ) == 'single' || ( request( 'purchase_type' ) == 'bulk' && $getproduct->is_connect_bulk_single == 1 ) ) {
            if ( $getproduct->qty < $totalqty ) {
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
        $productAmount = $getproduct->discount_price == null ? $getproduct->selling_price : $getproduct->discount_price;
        $vendor_id     = $getproduct->user_id;

        if ( request( 'purchase_type' ) == 'single' ) {
            $product_price = $getproduct->discount_price == null ? $getproduct->selling_price : $getproduct->discount_price;

            if ( $getproduct->discount_type == 'percent' ) {
                $affi_commission = ( $productAmount / 100 ) * $getproduct->discount_rate;
            } else {
                $affi_commission = $getproduct->discount_rate;
            }
            $totalproductprice          = $product_price * $totalqty;
            $total_affiliate_commission = $affi_commission * $totalqty;

            if ( $getproduct->single_advance_payment_type == 'percent' ) {
                $advancepayment = ( $product_price / 100 ) * $getproduct->advance_payment;
            } else {
                $advancepayment = $getproduct->advance_payment;
            }

            $totaladvancepayment = $getproduct->advance_payment * $totalqty;
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

        if ( Cart::where( 'product_id', $product_id )->where( 'user_id', $user_id )->exists() ) {
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
                $totaladvancepayment
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
    public function cart(Request $request)
    {
        $cart = Cart::where('user_id', auth()->id())->with('cartDetails', 'product')->get();

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

        if ( $cart->purchase_type === 'single' || ( $cart->purchase_type === 'bulk' && $product->is_connect_bulk_single == 1 ) ) {
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

        try {
            $pricing = $this->calculateCartPricing( $product, $cart->purchase_type, $totalqty );
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

        $cart->load( ['cartDetails.color', 'cartDetails.size', 'cartDetails.unit'] );

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
    private function calculateCartPricing( object $product, string $purchaseType, int $totalqty ): array
    {
        $productAmount = $product->discount_price == null ? $product->selling_price : $product->discount_price;

        if ( $purchaseType === 'single' ) {
            $productPrice = $productAmount;

            if ( $product->discount_type == 'percent' ) {
                $affiliateCommission = ( $productPrice / 100 ) * $product->discount_rate;
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
                'total_product_price'        => $productPrice * $totalqty,
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
}
