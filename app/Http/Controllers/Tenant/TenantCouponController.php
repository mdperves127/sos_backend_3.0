<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TenantCoupon;
use App\Services\TenantCouponService;
use Illuminate\Support\Facades\Auth;

class TenantCouponController extends Controller
{
    public function index()
    {
        $coupons = TenantCoupon::all();
        return response()->json(
            [
                'message' => 'Coupons fetched successfully',
                'success' => true,
                'data' => $coupons,
            ]
        );
    }
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'discount_type' => 'required|string|max:255',
            'discount_value' => 'required|numeric',
            'min_order_amount' => 'required|numeric',
            'max_discount_amount' => 'required|numeric',
            'usage_limit' => 'required|integer',
            'usage_limit_per_user' => 'required|integer',
            'valid_from' => 'required|date',
            'valid_to' => 'required|date',
            'status' => 'required|string|max:255',
        ]);
        $coupon = TenantCoupon::create($request->all());
        return response()->json(
            [
                'message' => 'Coupon created successfully',
                'success' => true,
                'coupon' => $coupon,
            ]
        );
    }
    public function show($id)
    {
        $coupon = TenantCoupon::find($id);
        if (!$coupon) {
            return response()->json(
                [
                    'message' => 'Coupon not found',
                    'success' => false,
                ],
                404
            );
        }
        return response()->json([
            'message' => 'Coupon fetched successfully',
            'success' => true,
            'coupon' => $coupon,
        ]);
    }
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'discount_type' => 'required|string|max:255',
            'discount_value' => 'required|numeric',
            'min_order_amount' => 'required|numeric',
            'max_discount_amount' => 'required|numeric',
            'usage_limit' => 'required|integer',
            'usage_limit_per_user' => 'required|integer',
            'valid_from' => 'required|date',
            'valid_to' => 'required|date',
            'status' => 'required|string|max:255',
        ]);
        $coupon = TenantCoupon::find($id);
        if (!$coupon) {
            return response()->json(
                [
                    'message' => 'Coupon not found',
                    'success' => false,
                ],
                404
            );
        }
        $coupon->update($request->all());
        return response()->json([
            'message' => 'Coupon updated successfully',
            'success' => true,
            'coupon' => $coupon,
        ]);
    }
    public function destroy($id)
    {
        $coupon = TenantCoupon::find($id);
        if (!$coupon) {
            return response()->json(
                [
                    'message' => 'Coupon not found',
                    'success' => false,
                ],
                404
            );
        }
        $coupon->delete();
        return response()->json([
            'message' => 'Coupon deleted successfully',
            'success' => true,
        ]);
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

        $result = TenantCouponService::validateForCheckout(
            (string) $request->input( 'code' ),
            (float) $request->input( 'order_amount' ),
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
            'status'          => 200,
            'message'         => 'Coupon applied successfully.',
            'coupon'          => [
                'id'             => $coupon->id,
                'name'           => $coupon->name,
                'code'           => $coupon->code,
                'discount_type'  => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
            ],
            'discount_amount' => $result['discount_amount'],
            'payable_amount'  => $result['payable_amount'],
        ] );
    }
}
