<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCouponRequest;
use App\Http\Requests\UpdateCouponRequest;
use App\Models\Coupon;
use App\Models\CouponUsed;
use App\Models\User;
use App\Services\Admin\CouponService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;

class CouponController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        if ( checkpermission( 'active-coupon' ) != 1 ) {
            return $this->permissionmessage();
        }

        // Check if the user is an employee and has permission
        if ( Auth::user()->is_employee === 'yes' && employee( 'coupon' ) === null ) {
            return $this->employeeMessage();
        }

        $search = request( 'search', '' );
        $data   = Coupon::on('mysql')
            ->latest()
            ->with( [
                'user:id,name,email',
                'tenant:id,owner_name,company_name,email',
            ] )
        // ->when( ( request( 'form' ) != '' ) && request( 'to' ) != '', function ( $query ) {
        //     $fromDate = Carbon::parse( request( 'form' ) );
        //     $toDate   = Carbon::parse( request( 'to' ) )->addDay( 1 );

        //     $query->withCount( ['couponused' => function ( $query ) use ( $fromDate, $toDate ) {
        //         $query->whereBetween( 'created_at', [$fromDate, $toDate] );
        //     }] )
        //         ->withSum( ['couponused' => function ( $query ) use ( $fromDate, $toDate ) {
        //             $query->whereBetween( 'created_at', [$fromDate, $toDate] );
        //         }], 'total_commission' );
        // }, function ( $query ) {
        //     $query->withCount( 'couponused' )
        //         ->withSum( 'couponused', 'total_commission' );
        // } )
            ->when( request( 'form' ) && request( 'to' ), function ( $query ) {
                $fromDate = Carbon::parse( request( 'form' ) );
                $toDate   = Carbon::parse( request( 'to' ) )->addDay( 1 );
                $query->whereBetween( 'created_at', [$fromDate, $toDate] );
            } )
            ->withCount( 'couponused' )->withSum( 'couponused', 'total_commission' )

            ->when( request( 'start_amount' ) && request( 'end_amount' ), function ( $query ) {
                $query->whereBetween( 'amount', [request( 'start_amount' ), request( 'end_amount' )] );
            } )

            ->when( request( 'start_commission' ) && request( 'end_commission' ), function ( $query ) {
                $query->whereBetween( 'commission', [request( 'start_commission' ), request( 'end_commission' )] );
            } )
            ->when( $search, function ( $query ) use ( $search ) {
                $query->search( $search );
            } )
            ->paginate( 10 );

        $data->getCollection()->transform( function ( Coupon $coupon ) {
            if ( $coupon->tenant ) {
                $coupon->setRelation( 'user', (object) [
                    'id'    => $coupon->tenant->id,
                    'name'  => $coupon->tenant->owner_name ?: $coupon->tenant->company_name,
                    'email' => $coupon->tenant->email,
                ] );
            }

            return $coupon;
        } );

        return $this->response( $data );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreCouponRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store( StoreCouponRequest $request ) {
        if ( checkpermission( 'create-coupon' ) != 1 ) {
            return $this->permissionmessage();
        }

        $validatedData = $request->validated();

        CouponService::create( $validatedData );

        return $this->response( 'Coupon created successfull!' );
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function show( Coupon $coupon ) {
        return $this->response( $coupon );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateCouponRequest  $request
     * @param  \App\Models\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function update( UpdateCouponRequest $request, Coupon $coupon ) {
        if ( !$coupon ) {
            return responsejson( 'Not found', 'fail' );
        }
        $validatedData = $request->validated();
        $coupon->update( $validatedData );

        return $this->response( 'Updated Successfull' );
    }
    public function couponUpdate( UpdateCouponRequest $request, $id ) {
        $coupon = Coupon::find( $id );
        if ( ! $coupon ) {
            return responsejson( 'Not found', 'fail' );
        }

        $coupon->update( $request->validated() );

        return $this->response( 'Updated Successfull' );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function destroy( Coupon $coupon ) {
        if ( !$coupon ) {
            return responsejson( 'Not found', 'fail' );
        }
        $coupon->delete();

        return $this->response( 'Coupon deleted successfull' );
    }

    function couponusers() {
        // $data = DB::table( 'users' )->whereIn( 'role_as', [2, 3] )->where( 'deleted_at', null )
        //     ->select( 'id', 'email' )
        //     ->get();
        $data = Tenant::on('mysql')->select('id', 'email')->get();
        return $this->response( $data );
    }

    public function couponUseList() {
        $couponUseList = CouponUsed::latest()
            ->when( request( 'from' ) && request( 'to' ), function ( $query ) {
                $fromDate = Carbon::parse( request( 'from' ) );
                $toDate   = Carbon::parse( request( 'to' ) )->addDay( 1 );
                $query->whereBetween( 'created_at', [$fromDate, $toDate] );
            } )
            ->with( 'user:id,name,email' )
            ->get();

        // ->select('*', DB::raw('SUM(total_commission) as total_commission'))
        // ->groupBy('user_id')
        // ->get();

        return response()->json( [
            'status'        => 200,
            'couponUseList' => $couponUseList,
            // 'total_amount'  => $total_amount,
        ] );
    }
}
