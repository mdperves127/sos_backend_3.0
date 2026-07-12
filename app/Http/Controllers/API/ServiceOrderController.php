<?php

namespace App\Http\Controllers\API;

use App\Models\ServiceOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerServiceStatus;
use App\Http\Requests\StoreServiceOrderRequest;
use App\Http\Requests\UpdateServiceOrderRequest;
use App\Models\ServicePackage;
use App\Services\CustomerService;
use App\Services\ServiceService;
use App\Services\SosService;
use Illuminate\Support\Facades\Auth;

class ServiceOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $query = ServiceOrder::on( 'mysql' )
            ->when( request( 'search' ) != '', function ( $query ) {
                $query->where( 'trxid', 'like', '%' . request( 'search' ) . '%' );
            } )
            ->when( request( 'status' ), function ( $query ) {
                $query->where( 'status', request( 'status' ) );
            } );

        $query = $this->applyServiceOrderContext( $query );

        if ( ! $query ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Tenant or user context is required.',
            ], 400 );
        }

        if ( $this->isTenantMerchantContext() ) {
            $query->where( 'is_paid', 1 );
        }

        $serviceOrder = $query
            ->with( ['servicedetails', 'packagedetails', 'vendor', 'customerdetails'] )
            ->latest()
            ->paginate( 10 );

        return response()->json( [
            'status'  => 200,
            'message' => 'Service orders fetched successfully',
            'data'    => $serviceOrder,
        ] );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreServiceOrderRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreServiceOrderRequest $request)
    {
        $validateData = $request->validated();
        return ServiceService::store($validateData);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\ServiceOrder  $serviceOrder
     * @return \Illuminate\Http\Response
     */
    public function show( $id )
    {
        $baseQuery = ServiceOrder::on( 'mysql' );
        $baseQuery = $this->applyServiceOrderContext( $baseQuery );

        if ( ! $baseQuery ) {
            return responsejson( 'Not found', 'fail' );
        }

        if ( $this->isTenantMerchantContext() ) {
            $baseQuery->where( 'is_paid', 1 );
        }

        $serviceOrder = $baseQuery
            ->with( ['servicedetails', 'packagedetails', 'requirementsfiles', 'vendor:id,name,email', 'customerdetails', 'servicerating', 'orderdelivery' => function ( $query ) {
                $query->with( 'deliveryfiles' );
            }] )
            ->where( 'id', $id )
            ->first();

        if ( ! $serviceOrder ) {
            return responsejson( 'Not found', 'fail' );
        }

        return $this->response( $serviceOrder );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateServiceOrderRequest  $request
     * @param  \App\Models\ServiceOrder  $serviceOrder
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateServiceOrderRequest $request, ServiceOrder $serviceOrder)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\ServiceOrder  $serviceOrder
     * @return \Illuminate\Http\Response
     */
    public function destroy(ServiceOrder $serviceOrder)
    {
        //
    }
    function status(CustomerServiceStatus $request)
    {
        $validateData = $request->validated();
        return  CustomerService::service($validateData);
    }

    public function serviceOrderCount() {
        $query = ServiceOrder::on( 'mysql' );
        $query = $this->applyServiceOrderContext( $query );

        if ( ! $query ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Tenant or user context is required.',
            ], 400 );
        }

        return response()->json( [
            'all'       => (clone $query)->count(),
            'success'   => (clone $query)->where( 'status', 'success' )->count(),
            'delivered' => (clone $query)->where( 'status', 'delivered' )->count(),
            'revision'  => (clone $query)->where( 'status', 'revision' )->count(),
            'pending'   => (clone $query)->where( ['status' => 'pending', 'is_paid' => 1] )->count(),
            'canceled'  => (clone $query)->where( 'status', 'canceled' )->count(),
            'progress'  => (clone $query)->where( 'status', 'progress' )->count(),
        ] );
    }

    private function isTenantMerchantContext(): bool
    {
        return function_exists( 'tenant' ) && tenant();
    }

    private function applyServiceOrderContext( $query ) {
        if ( $this->isTenantMerchantContext() ) {
            return $query->where( 'tenant_id', tenant()->id );
        }

        if ( auth()->check() ) {
            return $query->where( 'user_id', auth()->id() );
        }

        return null;
    }
}
