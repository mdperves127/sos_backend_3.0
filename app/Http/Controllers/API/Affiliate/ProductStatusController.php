<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\User;
use App\Notifications\AffiliateProductRequestNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ProductStatusController extends Controller {
    public function AffiliatorProducts() {
        $product = Product::query()
            ->where( 'status', 'active' )
            ->where( 'is_affiliate', '1' )
            ->when( request( 'search' ), fn( $q, $name ) => $q->where( 'name', 'like', "%{$name}%" ) )
            ->when( request( 'warranty' ), fn( $q, $warranty ) => $q->where( 'warranty', 'like', "%{$warranty}%" ) )
            ->when( request( 'category_id' ), function ( $query ) {
                $query->where( 'category_id', request( 'category_id' ) );
            } )
            ->when( request( 'start_stock' ) && request( 'end_stock' ), function ( $query ) {
                $query->whereBetween( 'qty', [request( 'start_stock' ), request( 'end_stock' )] );
            } )
            ->when( request()->has( 'start_price' ) && request()->has( 'end_price' ), function ( $query ) {
                $query->where( function ( $subQuery ) {
                    $subQuery->whereBetween( DB::raw( 'CASE
                                WHEN discount_price IS NULL THEN selling_price
                                ELSE discount_price
                                END' ), [request( 'start_price' ), request( 'end_price' )] );
                } );
            } )
            ->when( request( 'start_commission' ) && request( 'end_commission' ), function ( $query ) {
                $query->whereBetween( 'discount_rate', [request( 'start_commission' ), request( 'end_commission' )] );
            } )

            ->when( request( 'rating' ), function ( $query ) {
                $query->whereHas( 'productrating', function ( $q ) {
                    $q->where( 'rating', request( 'rating' ) );
                } );
            } )

            ->whereHas( 'productVariant' )

            ->when( request( 'high_to_low' ), function ( $query ) {
                $query->orderBy( DB::raw( 'CASE
                    WHEN discount_price IS NULL THEN selling_price
                    ELSE discount_price
                    END' ), 'desc' );
            } )

            ->when( request( 'low_to_high' ), function ( $query ) {
                $query->orderBy( DB::raw( 'CASE
                    WHEN discount_price IS NULL THEN selling_price
                    ELSE discount_price
                    END' ), 'ASC' );
            } )

            ->when( request( 'top_sale' ), function ( $query ) {
                $query->withCount( 'orderDetails' )
                    ->orderByDesc( 'order_details_count' );
            } )

            ->whereHas( 'vendor', function ( $query ) {
                $query->withCount( ['vendoractiveproduct' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->withwhereHas( 'usersubscription', function ( $query ) {
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
                    } )
                    ->withSum( 'usersubscription', 'affiliate_request' )
                    ->having( 'vendoractiveproduct_count', '<', \DB::raw( 'usersubscription_sum_affiliate_request' ) );
            } )
            ->whereDoesntHave( 'productdetails', function ( $query ) {
                $query->where( 'user_id', auth()->id() );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    public function AffiliatorProductPendingProduct() {
        $userId     = Auth::id();
        $searchTerm = request( 'search' );

        $pending = ProductDetails::with( 'product' )
            ->where( 'user_id', $userId )
            ->where( 'status', 2 )
            ->whereHas( 'product' )
            ->when( $searchTerm != '', function ( $query ) use ( $searchTerm ) {
                $query->whereHas( 'product', function ( $query ) use ( $searchTerm ) {
                    $query->where( 'name', 'like', '%' . $searchTerm . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $searchTerm . '%' );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'pending' => $pending,
        ] );
    }

    public function AffiliatorProductActiveProduct() {
        $userId     = Auth::id();
        $searchTerm = request( 'search' );

        $active = ProductDetails::query()
            ->with( 'product' )
            ->where( ['user_id' => $userId, 'status' => 1] )
            ->whereHas( 'product' )
            ->when( $searchTerm != '', function ( $query ) use ( $searchTerm ) {
                $query->whereHas( 'product', function ( $query ) use ( $searchTerm ) {
                    $query->where( 'name', 'like', '%' . $searchTerm . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $searchTerm . '%' );
            } )
            ->whereHas( 'vendor', function ( $query ) {
                $query->withCount( ['vendoractiveproduct' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->withwhereHas( 'usersubscription', function ( $query ) {

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
                // ->withSum('usersubscription', 'affiliate_request')
                // ->having('vendoractiveproduct_count', '<=', \DB::raw('usersubscription_sum_affiliate_request'));
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status' => 200,
            'active' => $active,
        ] );
    }

    function vendorexpireproducts() {
        $userId     = Auth::id();
        $searchTerm = request( 'search' );

        $active = ProductDetails::with( 'product' )->where( 'user_id', $userId )
            ->where( 'status', 1 )
            ->whereHas( 'product' )
            ->when( $searchTerm != '', function ( $query ) use ( $searchTerm ) {
                $query->whereHas( 'product', function ( $query ) use ( $searchTerm ) {
                    $query->where( 'name', 'like', '%' . $searchTerm . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $searchTerm . '%' );
            } )
            ->whereHas( 'vendor', function ( $query ) {

                $query->withwhereHas( 'usersubscription', function ( $query ) {

                    $query->where( function ( $query ) {
                        $query->whereHas( 'subscription', function ( $query ) {
                            $query->where( 'plan_type', 'freemium' );
                        } )
                            ->where( 'expire_date', '<', now() );
                    } )
                        ->orwhere( function ( $query ) {
                            $query->whereHas( 'subscription', function ( $query ) {
                                $query->where( 'plan_type', '!=', 'freemium' );
                            } )
                                ->where( 'expire_date', '<', now()->subMonth( 1 ) );
                        } );
                } );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status' => 200,
            'active' => $active,
        ] );
    }

    public function AffiliatorProductRejct() {
        $userId     = Auth::id();
        $searchTerm = request( 'search' );

        $reject = ProductDetails::query()
            ->with( ['product', 'vendor:id,name'] )
            ->where( ['user_id' => $userId, 'status' => 3] )
            ->whereHas( 'product' )
            ->when( $searchTerm != '', function ( $query ) use ( $searchTerm ) {
                $query->whereHas( 'product', function ( $query ) use ( $searchTerm ) {
                    $query->where( 'name', 'like', '%' . $searchTerm . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $searchTerm . '%' );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'pending' => $reject,
        ] );
    }

    public function AffiliatorProductRequest( Request $request, $id ) {

        $getmembershipdetails = getmembershipdetails();
        $acceptableproduct    = ProductDetails::where( ['user_id' => userid(), 'status' => 1] )->count();

        $productecreateqty = $getmembershipdetails?->product_request;

        $totalcreatedproduct = ProductDetails::where( 'user_id', userid() )->count();

        if ( ismembershipexists() != 1 ) {
            return responsejson( 'You do not have a membership', 'fail' );
        }

        if ( isactivemembership() != 1 ) {
            return responsejson( 'Membership expired!', 'fail' );
        }

        if ( $productecreateqty <= $totalcreatedproduct ) {
            return responsejson( 'You can not send product request more then ' . $productecreateqty . '.', 'fail' );
        }

        // if ($getmembershipdetails?->product_approve <= $acceptableproduct) {
        //     return responsejson('Vendor product accept limit over.', 'fail');
        // }

        $existproduct = Product::query()
            ->where( 'status', 'active' )
            ->whereHas( 'vendor', function ( $query ) {
                $query->withCount( ['vendoractiveproduct' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->withwhereHas( 'usersubscription', function ( $query ) {

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
                    } )
                    ->withSum( 'usersubscription', 'affiliate_request' )
                    ->having( 'vendoractiveproduct_count', '<', \DB::raw( 'usersubscription_sum_affiliate_request' ) );
            } )
            ->find( request( 'product_id' ) );

        if ( !$existproduct ) {
            return $this->response( 'Product not fount' );
        }

        $product             = new ProductDetails();
        $product->status     = 2;
        $product->product_id = $existproduct->id;
        $product->vendor_id  = $existproduct->user_id;
        $product->user_id    = auth()->id();
        $product->reason     = request( 'reason' );
        $product->uniqid     = uniqid();
        $product->save();

        $user = User::where( 'id', $product->vendor_id )->first();
        Notification::send( $user, new AffiliateProductRequestNotification( $user, $product ) );
        return response()->json( [
            'status'  => 200,
            'message' => 'Product Request Successfully Please Wait',
        ] );
    }
}
