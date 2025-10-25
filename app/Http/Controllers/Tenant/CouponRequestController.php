<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\CouponSendRequest;
use App\Models\CouponRequest;

class CouponRequestController extends Controller
{
    function store(CouponSendRequest $request)
    {
        CouponRequest::on('mysql')->create([
            'comments' => request('comments'),
            'tenant_id' => tenant()->id
        ]);

        return $this->response('Coupon request send successfully!');
    }

    function getcouponrequest()
    {
        $couponrequest = CouponRequest::on('mysql')
            ->where('tenant_id', tenant()->id)
            ->latest()
            ->paginate(10);

        return $this->response($couponrequest);
    }
}
