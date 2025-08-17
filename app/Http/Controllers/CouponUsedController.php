<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCouponUsedRequest;
use App\Http\Requests\UpdateCouponUsedRequest;
use App\Models\Coupon;
use App\Models\CouponUsed;
use App\Models\User;

class CouponUsedController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreCouponUsedRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreCouponUsedRequest $request)
    {
        // $validaData = $request->validated();
        // $coupon = Coupon::where('name',$validaData['name'])->first();
        // $user = User::find($coupon->user_id);

        // CouponUsed::create([
        //     'user_id'=>userid(),
        //     'coupon_id'=>$coupon->id,
        // ]);
        // $user->balance =

    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\CouponUsed  $couponUsed
     * @return \Illuminate\Http\Response
     */
    public function show(CouponUsed $couponUsed)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateCouponUsedRequest  $request
     * @param  \App\Models\CouponUsed  $couponUsed
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateCouponUsedRequest $request, CouponUsed $couponUsed)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\CouponUsed  $couponUsed
     * @return \Illuminate\Http\Response
     */
    public function destroy(CouponUsed $couponUsed)
    {
        //
    }
}
