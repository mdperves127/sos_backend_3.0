<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TenantCoupon;
use App\Services\TenantCouponService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TenantCouponController extends Controller
{
    public function index()
    {
        $coupons = TenantCoupon::all()->map( function ( TenantCoupon $coupon ) {
            $coupon->setAttribute(
                'discount_type',
                TenantCouponService::normalizeDiscountType( $coupon->discount_type )
            );

            return $coupon;
        } );

        return response()->json(
            [
                'message' => 'Coupons fetched successfully',
                'success' => true,
                'data' => $coupons,
            ]
        );
    }

    public function store( Request $request )
    {
        $request->validate( [
            'name'                 => 'required|string|max:255',
            'code'                 => 'required|string|max:255',
            'discount_type'        => ['required', 'string', Rule::in( ['percentage', 'flat', 'fixed', 'percent'] )],
            'discount_value'       => 'required|numeric|min:0',
            'min_order_amount'     => 'required|numeric|min:0',
            'max_discount_amount'  => 'required|numeric|min:0',
            'usage_limit'          => 'required|integer|min:0',
            'usage_limit_per_user' => 'required|integer|min:0',
            'valid_from'           => 'required|date',
            'valid_to'             => 'required|date|after_or_equal:valid_from',
            'status'               => 'required|string|max:255',
        ] );

        $payload = $request->all();
        $payload['discount_type'] = TenantCouponService::normalizeDiscountType( $request->input( 'discount_type' ) );

        if ( $payload['discount_type'] === 'percentage' && (float) $payload['discount_value'] > 100 ) {
            return response()->json( [
                'message' => 'Percentage discount cannot exceed 100.',
                'success' => false,
            ], 422 );
        }

        $coupon = TenantCoupon::create( $payload );

        return response()->json(
            [
                'message' => 'Coupon created successfully',
                'success' => true,
                'coupon'  => $coupon,
            ]
        );
    }

    public function show( $id )
    {
        $coupon = TenantCoupon::find( $id );
        if ( ! $coupon ) {
            return response()->json(
                [
                    'message' => 'Coupon not found',
                    'success' => false,
                ],
                404
            );
        }

        $coupon->setAttribute(
            'discount_type',
            TenantCouponService::normalizeDiscountType( $coupon->discount_type )
        );

        return response()->json( [
            'message' => 'Coupon fetched successfully',
            'success' => true,
            'coupon'  => $coupon,
        ] );
    }

    public function update( Request $request, $id )
    {
        $request->validate( [
            'name'                 => 'required|string|max:255',
            'code'                 => 'required|string|max:255',
            'discount_type'        => ['required', 'string', Rule::in( ['percentage', 'flat', 'fixed', 'percent'] )],
            'discount_value'       => 'required|numeric|min:0',
            'min_order_amount'     => 'required|numeric|min:0',
            'max_discount_amount'  => 'required|numeric|min:0',
            'usage_limit'          => 'required|integer|min:0',
            'usage_limit_per_user' => 'required|integer|min:0',
            'valid_from'           => 'required|date',
            'valid_to'             => 'required|date|after_or_equal:valid_from',
            'status'               => 'required|string|max:255',
        ] );

        $coupon = TenantCoupon::find( $id );
        if ( ! $coupon ) {
            return response()->json(
                [
                    'message' => 'Coupon not found',
                    'success' => false,
                ],
                404
            );
        }

        $payload = $request->all();
        $payload['discount_type'] = TenantCouponService::normalizeDiscountType( $request->input( 'discount_type' ) );

        if ( $payload['discount_type'] === 'percentage' && (float) $payload['discount_value'] > 100 ) {
            return response()->json( [
                'message' => 'Percentage discount cannot exceed 100.',
                'success' => false,
            ], 422 );
        }

        $coupon->update( $payload );

        return response()->json( [
            'message' => 'Coupon updated successfully',
            'success' => true,
            'coupon'  => $coupon->fresh(),
        ] );
    }

    public function destroy( $id )
    {
        $coupon = TenantCoupon::find( $id );
        if ( ! $coupon ) {
            return response()->json(
                [
                    'message' => 'Coupon not found',
                    'success' => false,
                ],
                404
            );
        }
        $coupon->delete();

        return response()->json( [
            'message' => 'Coupon deleted successfully',
            'success' => true,
        ] );
    }

    /**
     * Validate a tenant storefront coupon before checkout (website / guest checkout).
     */
    public function apply( Request $request )
    {
        $request->validate( [
            'code'         => 'required|string|max:255',
            'order_amount' => 'required|numeric|min:0',
        ] );

        $userId     = Auth::check() ? (int) Auth::id() : null;
        $guestEmail = $request->input( 'guest_email' );
        $orderAmount = (float) $request->input( 'order_amount' );

        $result = TenantCouponService::validateForCheckout(
            (string) $request->input( 'code' ),
            $orderAmount,
            $userId,
            $guestEmail
        );

        if ( isset( $result['error'] ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => $result['error'],
            ], 400 );
        }

        /** @var TenantCoupon $coupon */
        $coupon = $result['coupon'];

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => 'Coupon applied successfully.',
            'data'    => [
                'coupon'          => $coupon,
                'discount_type'   => $result['discount_type'],
                'discount_value'  => (float) $coupon->discount_value,
                'order_amount'    => $orderAmount,
                'discount_amount' => $result['discount_amount'],
                'payable_amount'  => $result['payable_amount'],
            ],
            'coupon'          => $coupon,
            'discount_type'   => $result['discount_type'],
            'discount_value'  => (float) $coupon->discount_value,
            'order_amount'    => $orderAmount,
            'discount_amount' => $result['discount_amount'],
            'payable_amount'  => $result['payable_amount'],
        ] );
    }
}
