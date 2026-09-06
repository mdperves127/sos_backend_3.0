<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use Illuminate\Http\Request;

/**
 * Merchant dashboard: list leftover storefront cart rows.
 * Completed checkouts delete carts, so remaining rows are unpaid/incomplete carts.
 */
class AbandonedCartController extends Controller
{
    public function index( Request $request )
    {
        $perPage = (int) $request->input( 'limit', $request->input( 'per_page', 10 ) );
        if ( $perPage < 1 ) {
            $perPage = 10;
        }
        $perPage = min( 100, $perPage );

        $vendorId = vendorId();

        $query = Cart::query()
            ->where( 'user_id', '>', 0 )
            ->where( function ( $q ) use ( $vendorId ) {
                $q->where( 'vendor_id', (string) $vendorId )
                    ->orWhere( 'vendor_id', $vendorId );
            } )
            ->with( [
                'product:id,name,image,sku',
                'user:id,name,email,number',
            ] );

        if ( $request->filled( 'search' ) ) {
            $search = trim( (string) $request->input( 'search' ) );
            $query->where( function ( $q ) use ( $search ) {
                $q->whereHas( 'user', function ( $userQuery ) use ( $search ) {
                    $userQuery->where( 'name', 'like', "%{$search}%" )
                        ->orWhere( 'number', 'like', "%{$search}%" )
                        ->orWhere( 'email', 'like', "%{$search}%" );
                } )->orWhereHas( 'product', function ( $productQuery ) use ( $search ) {
                    $productQuery->where( 'name', 'like', "%{$search}%" )
                        ->orWhere( 'sku', 'like', "%{$search}%" );
                } );
            } );
        }

        $carts = $query
            ->latest( 'updated_at' )
            ->paginate( $perPage )
            ->withQueryString()
            ->through( function ( Cart $cart ) {
                return [
                    'id'                => $cart->id,
                    'product'           => $cart->product?->name,
                    'product_id'        => $cart->product_id,
                    'product_image'     => $cart->product?->image,
                    'cart_value'        => (float) ( $cart->totalproductprice ?? 0 ),
                    'qty'               => (int) ( $cart->product_qty ?? 0 ),
                    'customer'          => $cart->user?->name,
                    'phone'             => $cart->user?->number,
                    'email'             => $cart->user?->email,
                    'last_activity'     => optional( $cart->updated_at )->toDateTimeString(),
                    'cart_created_time' => optional( $cart->created_at )->toDateTimeString(),
                ];
            } );

        return response()->json( [
            'status'  => 200,
            'message' => $carts,
        ] );
    }
}
