<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponSendRequest;
use App\Http\Requests\CouponstausRequest;
use App\Models\CouponRequest;
use Illuminate\Http\Request;

class CouponRequestController extends Controller
{
    function store(CouponSendRequest $request)
    {
        CouponRequest::create([
            'user_id' => auth()->id(),
            'comments' => request('comments')
        ]);

        return $this->response('Coupon request send successfully!');
    }

    function getcouponrequest()
    {
        $couponrequest = CouponRequest::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->first();

        if (!$couponrequest) {
            return responsejson('', 'success');
        }
        return $this->response($couponrequest);
    }

    function allcouponrequest()
    {

        if (request('status') == 'reject') {
            if (checkpermission('rejected-coupon') != 1) {
                return $this->permissionmessage();
            }
        }

        if (request('status') != 'reject') {
            if (checkpermission('request-coupon') != 1) {
                return $this->permissionmessage();
            }
        }


        $search = request('search', '');
        return CouponRequest::query()
            ->with('user')
            ->when(request('status') == 'reject', function ($query) use($search){
                $query->where('status', 'reject');
            }, function ($query) use ($search) {
                $query->where('status', '!=', 'reject');
            })
            ->when($search,function($query) use($search){
                $query->whereHas('user',function($query) use($search){
                    $query->where('email','like',"%{$search}%");
                });
            })
            ->latest()
            ->paginate(10);
    }

    function changestatus(CouponstausRequest $request, $id)
    {
        $couponrequest =  CouponRequest::find($id);
        if (!$couponrequest) {
            return responsejson('Not found', 'fail');
        }

        $couponrequest->status = request('status');
        if (request('status') == 'reject') {
            $couponrequest->reason = request('reason');
        }

        $couponrequest->save();
        return $this->response('success');
    }
    function deleterequest($id)
    {
        $couponrequest =  CouponRequest::find($id);
        if (!$couponrequest) {
            return $this->response('Not found', 'fail');
        }

        $couponrequest->delete();
        return $this->response('Coupon request deleted successfull');
    }
}
