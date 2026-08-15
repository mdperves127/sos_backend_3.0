<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCustomPackageRequest;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\SubscriptionRequest;
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

        $query = Subscription::query()->latest( 'id' );

        if ( request()->has( 'is_custom' ) ) {
            $query->where( 'is_custom', filter_var( request( 'is_custom' ), FILTER_VALIDATE_BOOLEAN ) );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $query->get(),
        ] );
    }

    /**
     * List only custom packages (created via custom-package API).
     */
    public function customPackages() {
        if ( checkpermission( 'subscription' ) != 1 ) {
            return $this->permissionmessage();
        }

        $data = Subscription::query()
            ->where( 'is_custom', true )
            ->latest( 'id' )
            ->get();

        return response()->json( [
            'status'  => 200,
            'message' => 'Custom packages fetched successfully.',
            'data'    => $data,
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
     * Create a custom subscription package.
     */
    public function store( CreateCustomPackageRequest $request ) {
        if ( checkpermission( 'subscription' ) != 1 ) {
            return $this->permissionmessage();
        }

        $package = SubscriptionService::store( $request->validated() );

        return response()->json( [
            'status'  => 200,
            'message' => 'Custom package created successfully.',
            'data'    => $package,
        ] );
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
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\StoreSubscriptionRequest  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update( StoreSubscriptionRequest $request, $id ) {

        $validateData = $request->validated();
        SubscriptionService::update( $validateData, $id );
        return $this->response( 'Subsciption Updated Successfuly' );

    }

    /**
     * Delete a custom package (soft delete). Only packages with is_custom = true.
     */
    public function destroyCustomPackage( $id ) {
        if ( checkpermission( 'subscription' ) != 1 ) {
            return $this->permissionmessage();
        }

        $package = Subscription::query()
            ->where( 'id', $id )
            ->where( 'is_custom', true )
            ->first();

        if ( ! $package ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Custom package not found.',
            ], 404 );
        }

        $package->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Custom package deleted successfully.',
        ] );
    }

    /**
     * Resource destroy — same as custom package delete when targeting a custom package.
     */
    public function destroy( $id ) {
        return $this->destroyCustomPackage( $id );
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
