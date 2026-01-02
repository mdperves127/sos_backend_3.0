<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Services\CrossTenantQueryService;
use Illuminate\Http\Request;
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

    public function AffiliatorProductSingle( $tenant_id, $id ) {

        $product = CrossTenantQueryService::getSingleFromTenant(
            $tenant_id,
            Product::class,
            function ( $query ) use ( $id ) {
                $query->with( [
                    // 'category',
                    // 'subcategory',
                    'productImage',
                    // 'brand',
                    'marketplaceCategory',
                    'marketplaceSubcategory',
                    'marketplaceBrand',
                    'productdetails' => function ( $query ) {
                        $query->where( 'status', 'active' );
                    },
                    'productrating.affiliate:id,name,image',
                    'productVariant.size',
                    'productVariant.unit',
                    'productVariant.color',
                    'productVariant.product',
                    'purchaseDetails.color',
                    'purchaseDetails.size',
                    'purchaseDetails.unit'
                ] )
                    ->where( 'status', 'active' )
                    ->withAvg( 'productrating', 'rating' )
                    ->where( 'id', $id );
            }
        );

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

    public function AffiliatorProductSingleAddProfit( Request $request, $id ) {
        $productDetails = ProductDetails::find($id);

        if (!$productDetails) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product details not found',
            ], 404);
        }

        $productDetails->profit_amount = $request->profit_amount;
        $productDetails->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Profit amount updated successfully',
            'productDetails' => $productDetails,
        ]);
    }
}
