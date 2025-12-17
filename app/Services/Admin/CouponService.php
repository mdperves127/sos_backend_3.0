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
        $coupon =  Coupon::on('mysql')->create($validatedData);
        $tenantId = $validatedData['tenant_id'];

        $couponrequest =  CouponRequest::on('mysql')->where(['tenant_id'=>$tenantId,'status'=>'pending'])->latest()->first();

        if($couponrequest){
            $couponrequest->status = 'active';
            $couponrequest->save();
            $couponrequest->delete();

        }

        return $coupon;
    }
}
