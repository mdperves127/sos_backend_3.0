<?php

namespace App\Services\Admin;

use App\Models\Coupon;
use App\Models\CouponRequest;

/**
 * Class CouponService.
 */
class CouponService
{

    static function create($validatedData)
    {
        $coupon =  Coupon::create($validatedData);
        $couponrequest =  CouponRequest::query()->where(['user_id'=>request('user_id'),'status'=>'pending'])->latest()->first();
        if($couponrequest){
            $couponrequest->status = 'active';
            $couponrequest->save();
            $couponrequest->delete();

        }

        return $coupon;
    }
}
