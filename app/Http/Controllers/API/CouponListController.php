<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;

class CouponListController extends Controller
{
    function index()
    {

        $coupon = Coupon::query()
            ->where('user_id', userid())
            ->withCount('couponused')
            ->withSum('couponused','total_commission')
            ->get();
        return $this->response($coupon);
    }
}
