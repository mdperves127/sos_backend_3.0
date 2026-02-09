<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\SubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Models\Subscription;
use App\Services\Admin\SubscriptionService;

class SubscriptionController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        if ( checkpermission( 'subscription' ) != 1 ) {
            return $this->permissionmessage();
        }

        $data = Subscription::all();
        return response()->json( [
            'status' => 200,
            'data'   => $data,
        ] );
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create() {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreSubscriptionRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store() {

    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Subscription  $subscription
     * @return \Illuminate\Http\Response
     */
    public function show( $id ) {
        if ( checkpermission( 'subscription' ) != 1 ) {
            return $this->permissionmessage();
        }

        $data = Subscription::find( $id );
        return response()->json( [
            'status' => 200,
            'data'   => $data,
        ] );
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Subscription  $subscription
     * @return \Illuminate\Http\Response
     */

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateSubscriptionRequest  $request
     * @param  \App\Models\Subscription  $subscription
     * @return \Illuminate\Http\Response
     */
    public function update( StoreSubscriptionRequest $request, $id ) {

        $validateData = $request->validated();
        SubscriptionService::update( $validateData, $id );
        return $this->response( 'Subsciption Updated Successfuly' );

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Subscription  $subscription
     * @return \Illuminate\Http\Response
     */
    public function destroy( Subscription $subscription ) {
        //
    }

    function requirement( SubscriptionRequest $request ) {
        $validateData = $request->validated();

        $subscription                    = Subscription::find( $validateData['subscription_id'] );
        $subscription->service_qty       = request( 'service_qty' );
        $subscription->product_qty       = request( 'product_qty' );
        $subscription->affiliate_request = request( 'affiliate_request' );

        $subscription->product_request = request( 'product_request' );
        $subscription->product_approve = request( 'product_approve' );
        $subscription->service_create  = request( 'service_create' );
        $subscription->chat_access     = request( 'chat_access' );
        $subscription->employee_create = request( 'employee_create' );
        $subscription->pos_sale_qty    = request( 'pos_sale_qty' );
        $subscription->has_website     = request( 'has_website' );
        $subscription->website_visits  = request( 'website_visits' );
        $subscription->save();

        return $this->response( 'Successfull' );
    }
}
