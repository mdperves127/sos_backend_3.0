<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminAdminStatusProgress;
use App\Http\Requests\AdvertiseDeliveryRequest;
use App\Http\Requests\CancelAdminAdvertiseRequest;
use App\Http\Requests\StoreAdminAdvertiseRequest;
use App\Http\Requests\UpdateAdminAdvertiseRequest;
use App\Models\AdminAdvertise;
use App\Models\DollerRate;
use App\Models\User;
use App\Services\Admin\AdminAdvertiseService;
use App\Services\PaymentHistoryService;
use Illuminate\Support\Facades\DB;

class AdminAdvertiseController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {

        if ( checkpermission( 'all-advertiser' ) != 1 ) {
            return $this->permissionmessage();
        }

        $data = AdminAdvertise::query()
            ->latest()
            ->when( request( 'search' ), fn( $q, $search ) => $q->where( 'unique_id', 'like', "%{$search}%" ) )
            ->where( 'is_paid', 1 )
            ->select( 'id', 'campaign_name', 'campaign_objective', 'budget_amount', 'start_date', 'end_date', 'is_paid', 'created_at', 'status', 'unique_id', 'user_id' )
            ->with( 'user:id,name,email' )
            ->paginate( 10 );

        return $this->response( $data );
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
     * @param  \App\Http\Requests\StoreAdminAdvertiseRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store( StoreAdminAdvertiseRequest $request ) {
        // $validator = Validator::make( $request->all(), [

        //     'number' => 'required|string',
        // ] );
        // if ( $validator->fails() ) {
        //     return response()->json( [
        //         'status'            => 400,
        //         'validation_errors' => $validator->messages(),
        //     ] );
        // }
        $advertise = AdminAdvertiseService::create( $request->validated() );

        return $this->response( $advertise );
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return \Illuminate\Http\Response
     */
    public function show( $id ) {
        // $userID = userid();

        $userID         = userid();

        $adminAdvertise = AdminAdvertise::on('mysql')->with( 'AdvertiseAudienceFile', 'advertisePlacement', 'advertiseLocationFiles', 'files', 'user:id,name,email,number,uniqid', 'tenant:id,company_name,owner_name,phone,email' )->find( $id );
        if ( $adminAdvertise ) {
            return response()->json( [
                'status'  => 200,
                'product' => $adminAdvertise,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No adminAdvertise data Found',
            ] );
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return \Illuminate\Http\Response
     */

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateAdminAdvertiseRequest  $request
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return \Illuminate\Http\Response
     */
    public function update( UpdateAdminAdvertiseRequest $request, $id ) {
        $validatData = $request->validated();
        AdminAdvertiseService::update( $validatData, $id );
        return $this->response( 'Advertise Updated Successfully' );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\AdminAdvertise  $adminAdvertise
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        $data = AdminAdvertise::find( $id );
        if ( $data ) {
            $data->delete();
            return $this->response( 'Item Deleted Successfully !' );
        } else {
            return $this->response( 'Item Not found!' );
        }
    }

    function status( AdminAdminStatusProgress $request ) {
        $validateData      = $request->validated();
        $advertise         = AdminAdvertise::find( $validateData['advertise_id'] );
        $advertise->status = "progress";
        $advertise->save();

        return $this->response( 'Progress successfully!' );
    }

    function delivery( AdvertiseDeliveryRequest $request ) {

        DB::transaction( function () {
            $advertise         = AdminAdvertise::find( request( 'advertise_id' ) );
            $advertise->status = "delivered";
            $advertise->save();

            if ( request()->hasFile( 'files' ) ) {
                foreach ( request( 'files' ) as $file ) {
                    $filename = uploadany_file( $file );

                    $advertise->files()->create( [
                        'name' => $filename,
                    ] );
                }
            }
        } );

        return $this->response( 'Delivered successfully!' );
    }

    function cancel( CancelAdminAdvertiseRequest $request ) {

        $advertise         = AdminAdvertise::on('mysql')->find( request( 'advertise_id' ) );
        $advertise->status = "cancel";
        $advertise->reason = request( 'reason' );
        $advertise->save();

        $user       = User::on('mysql')->find( $advertise->user_id );
        $dollerrate = DollerRate::on('mysql')->first()?->amount;

        $totalreturn = $dollerrate * request( 'return_balance' );
        $user->increment( 'balance', $totalreturn );

        PaymentHistoryService::store( uniqid(), $totalreturn, 'My wallet', 'Return ads amount', '+', '', $user->id );

        return $this->response( 'Cancel successfully!' );
    }
}
