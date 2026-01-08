<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CrossTenantQueryService;
use App\Enums\Status;
use App\Http\Requests\ProductRequest;
use App\Services\ProductCheckoutService;

class OrderController extends Controller
{
    function store( ProductRequest $request ) {

        $cart = Cart::where('id', $request->cart_id)->first();

        if ( !$cart || !$cart->tenant_id ) {
            return responsejson( 'Cart not found or missing tenant information', 'fail' );
        }

        // Get product from cart's tenant database
        $product = CrossTenantQueryService::getSingleFromTenant(
            $cart->tenant_id,
            Product::class,
            function ( $query ) use ( $cart ) {
                $query->where( ['id' => $cart->product_id, 'status' => 'active'] );
            }
        );

        if ( !$product ) {
            return responsejson( 'Product currently not available!' );
        }

        if ( $cart->purchase_type == 'single' ) {
            if ( $product->selling_type == 'bulk' ) {
                return responsejson( 'Something is wrong delete the cart.', 'fail' );
            }
        }

        $datas = collect( request( 'datas' ) );

        // if ( $cart->purchase_type == 'bulk' ) {
        //     $firstaddress = $datas->first();
        //     $variants     = collect( $firstaddress )['variants'];
        //     $totalqty     = collect( $variants )->sum( 'qty' );

        //     if ( $product->is_connect_bulk_single == 1 ) {
        //         if ( $product->qty < $totalqty ) {
        //             return responsejson( 'Product quantity not available!', 'fail' );
        //         }
        //     }
        // }

        if ( $cart->purchase_type == 'single' ) { //single

            $varients = $datas->pluck( 'variants' );

            $totalqty = collect( $varients )->collapse()->sum( 'qty' );

            if ( $product->qty < $totalqty ) {
                return responsejson( 'Product quantity not available!', 'fail' );
            }
        }

        if ( $product->status == Status::Pending->value ) {
            return responsejson( 'The product under construction!', 'fail' );
        }

        $uservarients = collect( request()->datas )->pluck( 'variants' )->collapse();

        // if ( $product->variants != '' ) {
        //     if ( ( $cart->purchase_type != 'bulk' ) && ( $product->is_connect_bulk_single != 1 ) ) {
        //         foreach ( $uservarients as $vr ) {
        //             $data = collect( $product?->productVariant?->variants )->where( 'id', $vr['variant_id'] )->where( 'qty', '>=', $vr['qty'] )->first();
        //             if ( !$data ) {
        //                 return responsejson( 'Something is wrong. Delete the cart', 'fail' );
        //             }
        //         }
        //     }

        // }

        // Get user - using auth()->user() or auth()->id() as needed
        $user = auth()->user();

        return ProductCheckoutService::store( $cart->id, $product->id, $totalqty, $user->id, request( 'datas' ), 'aamarpay', $cart->tenant_id );

    }
}
