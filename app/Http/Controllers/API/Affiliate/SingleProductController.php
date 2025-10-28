<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDetails;
use Illuminate\Support\Facades\Auth;

class SingleProductController extends Controller {

    public function AffiliatorProductSinglePage( $id ) {

        $userId         = Auth::id();
        $productDetails = ProductDetails::where( 'user_id', $userId )->with( ['product' => function ( $query ) {
            $query->with( 'productImage', 'sizes', 'colors' );
        }] )->where( 'id', $id )->first();

        return response()->json( [
            'status'         => 200,
            'productDetails' => $productDetails,
        ]);
    }

    public function AffiliatorProductSingle( $id ) {
        $product = Product::query()
            ->with( ['category', 'subcategory', 'productImage', 'brand', 'productdetails' => function ( $query ) {
                $query->where( ['user_id' => auth()->id(), 'status' => 3] );
            }, 'vendor:id,uniqid'] )
            ->where( 'status', 'active' )
            ->withAvg( 'productrating', 'rating' )
            ->with( 'productrating.affiliate:id,name,image' )
            ->with( 'productVariant', function ( $query ) {
                $query->with( 'size:id,name', 'unit:id,unit_name', 'color:id,name' );
            } )
            ->whereHas( 'vendor', function ( $query ) {
                $query->withCount( ['vendoractiveproduct' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {

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
                    ->having( 'vendoractiveproduct_count', '<=', \DB::raw( 'usersubscription_sum_affiliate_request' ) );
            } )
            ->find( $id );
        if ( $product ) {
            return response()->json( [
                'status'  => 200,
                'product' => $product,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Product Id Found',
            ] );
        }
    }

    public function AffiliatoractiveProduct( int $id ) {
        $product = Product::query()
            ->with( ['category', 'subcategory', 'productImage', 'brand', 'vendor:id,name,image', 'productdetails' => function ( $query ) {
                $query->where( ['user_id' => auth()->id(), 'status' => 3] );
            }] )
            ->where( 'status', 'active' )
            ->withAvg( 'productrating', 'rating' )
            ->with( 'productrating.affiliate:id,name,image' )
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
            } )
            ->whereHas( 'productdetails', function ( $query ) {
                $query->where( 'user_id', auth()->id() );
            } )
            ->find( $id );

        if ( $product ) {
            return response()->json( [
                'status'  => 200,
                'product' => $product,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Product Id Found',
            ] );
        }
    }
}
