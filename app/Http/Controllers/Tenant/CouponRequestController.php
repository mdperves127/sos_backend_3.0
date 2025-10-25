<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponSendRequest;
use App\Models\CouponRequest;
use Illuminate\Http\Request;

class CouponRequestController extends Controller {
    function store( CouponSendRequest $request ) {
        CouponRequest::on( 'mysql' )->create( [
            'comments'  => request( 'comments' ),
            'tenant_id' => tenant()->id,
        ] );

        return $this->response( 'Coupon request send successfully!' );
    }

    function getcouponrequest() {
        $couponrequest = CouponRequest::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->latest()->first();

        if ( $couponrequest ) {
            return $this->response( $couponrequest );
        }
        return response()->json(
            [
                'status'  => 400,
                'data'    => 'failed',
                'message' => 'No coupon request found',
            ]
        );

    }
}
