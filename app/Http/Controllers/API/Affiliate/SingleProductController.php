<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Services\CrossTenantQueryService;
use App\Services\DropshipperAccessService;
use App\Services\DropshipperPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

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

        $connection = $product ? DropshipperAccessService::merchantConnection( $tenant_id ) : null;

        // Private products answer exactly like unknown IDs for dropshippers without access.
        if ( $product && $connection && DropshipperAccessService::canAccessProduct( $connection, tenant() ? (string) tenant()->id : null, $product ) ) {
            $product->setAttribute( 'dropshipper_pricing', DropshipperPricingService::publicRules( $product ) );

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

    /**
     * Set the dropshipper's selling price (or legacy profit_amount margin) for a listed product.
     * The merchant's product price is never changed.
     */
    public function AffiliatorProductSingleAddProfit( Request $request, $id ) {
        $productDetails = ProductDetails::find($id);

        if (!$productDetails) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product details not found',
            ], 404);
        }

        if ( ! $request->has( 'selling_price' ) && ! $request->has( 'profit_amount' ) ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'selling_price or profit_amount is required.',
            ], 422 );
        }

        $product = DropshipperAccessService::accessibleProduct(
            (string) $productDetails->tenant_id,
            tenant() ? (string) tenant()->id : null,
            (int) $productDetails->product_id,
            false
        );

        if ( ! $product ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product details not found',
            ], 404 );
        }

        $basePrice = DropshipperPricingService::basePrice( $product );

        if ( $request->has( 'selling_price' ) ) {
            $requestedPrice = $request->input( 'selling_price' );
        } else {
            $margin = DropshipperPricingService::parseAmount( $request->input( 'profit_amount' ) );
            if ( $margin === null ) {
                return response()->json( [
                    'status'  => 422,
                    'message' => 'Profit amount must be a non-negative amount with at most 2 decimal places.',
                ], 422 );
            }
            $requestedPrice = number_format( $basePrice + $margin, 2, '.', '' );
        }

        $validation = DropshipperPricingService::validateSellingPrice( $product, $requestedPrice );
        if ( ! $validation['ok'] ) {
            return response()->json( [
                'status'  => 422,
                'message' => $validation['message'],
                'pricing' => DropshipperPricingService::publicRules( $product ),
            ], 422 );
        }

        $productDetails->profit_amount = round( max( 0, $validation['price'] - $basePrice ), 2 );
        if ( Schema::hasColumn( $productDetails->getTable(), 'selling_price' ) ) {
            $productDetails->selling_price = $validation['price'];
        }
        $productDetails->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Profit amount updated successfully',
            'productDetails' => $productDetails,
            'pricing' => DropshipperPricingService::publicRules( $product ),
        ]);
    }
}
