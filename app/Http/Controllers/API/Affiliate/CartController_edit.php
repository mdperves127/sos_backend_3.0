<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductAddToCartRequest;
use App\Models\Cart;
use App\Models\CartDetails;
use App\Models\CourierCredential;
use App\Models\DeliveryCharge;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;

class CartController extends Controller {
    //
    public function addtocart( ProductAddToCartRequest $request ) {
        $validatedData = $request->validated();

        $getproduct = Product::find( request( 'product_id' ) );
        // $totalqty   = collect( request( 'cartItems' ) )->sum( 'qty' );
        $totalqty = collect( request( 'qty' ) )->sum();

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

        $user_id       = userid();
        $product_id    = $getproduct->id;
        $productAmount = $getproduct->selling_price;
        $vendor_id     = $getproduct->user_id;

        if ( request( 'purchase_type' ) == 'single' ) {
            $product_price = $getproduct->selling_price;

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

        $cartitem                             = new Cart();
        $cartitem->user_id                    = $user_id;
        $cartitem->product_id                 = $product_id;
        $cartitem->product_price              = $product_price;
        $cartitem->vendor_id                  = $vendor_id;
        $cartitem->amount                     = $affi_commission;
        $cartitem->category_id                = $getproduct->category_id;
        $cartitem->product_qty                = $totalqty;
        $cartitem->totalproductprice          = $totalproductprice;
        $cartitem->total_affiliate_commission = $total_affiliate_commission;
        $cartitem->purchase_type              = request( 'purchase_type' );
        $cartitem->advancepayment             = $advancepayment;
        $cartitem->totaladvancepayment        = $totaladvancepayment;

        $cartitem->save();

        // foreach ( request( 'cartItems' ) as $data ) {
        //     $colors[]   = $data['color'] ?? null;
        //     $sizes[]    = $data['size'] ?? null;
        //     $qnts[]     = $data['qty'];
        //     $variants[] = $data['id'] ?? null;
        // }

        // foreach ( $qnts as $key => $value ) {
        //     CartDetails::create( [
        //         'cart_id'    => $cartitem->id,
        //         'color'      => $colors[$key],
        //         'size'       => $sizes[$key],
        //         'qty'        => $value,
        //         'variant_id' => $variants[$key],
        //     ] );
        // }

        // return $request->cartItems[0]['color_id'];

        $colors   = $request->color_id;
        $sizes    = $request->size_id;
        $qtys     = $request->qty;
        $variants = $request->unit_id;

        // return $cartItems;
        $cartItems = request( 'cartItems' );
        foreach ( $colors as $key => $item ) {
            $cart_item             = new CartDetails;
            $cart_item->cart_id    = $cartitem->id;
            $cart_item->color      = $item;
            $cart_item->size       = $sizes[$key];
            $cart_item->qty        = $qtys[$key];
            $cart_item->variant_id = $variants[$key];
            $cart_item->save();
        }

        return response()->json( [
            'status'  => 201,
            'message' => 'Added to Cart',
        ] );
    }

    public function viewcart() {

        $user_id   = auth()->user()->id;
        $cartitems = Cart::where( 'user_id', $user_id )->with( ['cartDetails', 'product:id,name'] )

            ->whereHas( 'product', function ( $query ) {
                $query->where( 'status', 'active' )
                    ->whereHas( 'productdetails', function ( $query ) {
                        $query->where( 'status', 1 );
                    } )
                    ->whereHas( 'vendor', function ( $query ) {
                        $query->withwhereHas( 'usersubscription', function ( $query ) {

                            $query->where( function ( $query ) {
                                $query->whereHas( 'subscription', function ( $query ) {
                                    $query->where( 'plan_type', 'freemium' );
                                } )
                                    ->where( 'expire_date', '>', now() );
                            } )
                                ->orwhere( function ( $query ) {
                                    $query->whereHas( 'subscription', function ( $query ) {
                                        $query->where( 'plan_type', '!=', 'freemium' );
                                    } )
                                        ->where( 'expire_date', '>', now()->subMonth( 1 ) );
                                } );
                        } );
                    } );
            } )
            ->get();

        return response()->json( [
            'status' => 200,
            'cart'   => $cartitems,
        ] );
    }

    public function deleteCartitem( $cart_id ) {

        $cartitem = Cart::where( 'id', $cart_id )->where( 'user_id', userid() )->first();
        if ( $cartitem ) {
            $cartitem->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'Cart Item Removed Successfully.',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'Cart Item not Found',
            ] );
        }
    }

    public function updatequantity() {
        echo "DOne";
    }

    function affiliatorCart( $id ) {
        $cart = Cart::where( 'user_id', userid() )
            ->whereHas( 'product', function ( $query ) {
                $query->where( 'status', 'active' )
                    ->whereHas( 'vendor', function ( $query ) {
                        $query->withwhereHas( 'usersubscription', function ( $query ) {

                            $query->where( function ( $query ) {
                                $query->whereHas( 'subscription', function ( $query ) {
                                    $query->where( 'plan_type', 'freemium' );
                                } )
                                    ->where( 'expire_date', '>', now() );
                            } )
                                ->orwhere( function ( $query ) {
                                    $query->whereHas( 'subscription', function ( $query ) {
                                        $query->where( 'plan_type', '!=', 'freemium' );
                                    } )
                                        ->where( 'expire_date', '>', now()->subMonth( 1 ) );
                                } );
                        } );
                    } );
            } )
            ->find( $id );

        $deliverArea = DeliveryCharge::where( 'vendor_id', $cart->vendor_id )->select( 'id', 'area', 'charge' )->get();
        $courier     = CourierCredential::where( 'vendor_id', $cart->vendor_id )->first() ?? '';
        if ( !$cart ) {
            return response()->json( [
                'status' => 'Not found',
            ] );
        } else {
            $data        = Cart::find( $id )->load( ['product:id,name', 'cartDetails'] );
            $deliverArea = $deliverArea;
            return response()->json( [
                "data"         => $data,
                "deliveryArea" => $deliverArea,
                "courier"      => $courier,

            ] );
        }
    }
}
