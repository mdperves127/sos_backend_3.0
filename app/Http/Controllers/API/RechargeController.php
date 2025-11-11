<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\RechargeRequest;
use App\Models\PaymentStore;
use App\Models\Settings;
use App\Models\User;
use App\Services\AamarPayService;
use App\Services\PaymentHistoryService;

class RechargeController extends Controller {

    function recharge( RechargeRequest $request ) {
        $setting = Settings::on( 'mysql' )->first();

        $validateData            = $request->validated();
        $validateData['user_id'] = auth()->id();

        //For extra Charge
        // if ($setting->extra_charge_status == "on") {
        //     $extra_charge = extraCharge($validateData['amount'], $setting->extra_charge);
        //     $total_amount = $validateData['amount'] + $extra_charge;
        // } else {
        //     $total_amount = $validateData['amount'];
        // }

        $total_amount = $validateData['amount'];

        $trxid      = uniqid();
        $type       = "recharge";
        $successurl = url( 'api/aaparpay/recharge-success-for-us' );

        // $validateData['extra_charge'] = number_format( $extra_charge, 2 ); //For extra charge
        PaymentStore::create( [
            'payment_gateway'         => 'aamarpay',
            'trxid'                   => $trxid,
            'payment_type'            => 'recharge',
            'info'                    => $validateData,
            'customer_requirement_id' => 0,
        ] );
        // return 2;

        return AamarPayService::gateway( $total_amount, $trxid, $type, $successurl );
    }

    public function allUserBalance() {
        $users = User::where( 'role_as', '!=', 1 )->whereNull( 'is_employee' )
            ->when( request( 'email' ), function ( $query ) {
                $query->where( 'email', request( 'email' ) );
            } )
            ->select( 'id', 'name', 'email', 'balance' )->paginate( 12 );

        return response()->json( [
            'status' => 200,
            'users'  => $users,
        ] );
    }

    public function addUserBalance( $id ) {
        $user  = User::find( $id )->increment( 'balance', request( 'amount' ) );
        $trxid = uniqid();
        $type  = "recharge";

        PaymentHistoryService::store( uniqid(), request( 'amount' ), 'Manually by admin', 'Recharge', '+', '', $id );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully recharge amount ' . request( 'amount' ) . ' ! ',
        ] );
    }

    public function editUserBalance( $id ) {
        $user          = User::find( $id );
        $user->balance = request( 'amount' );
        $user->save();

        PaymentHistoryService::store( uniqid(), request( 'amount' ), 'Edited by admin', 'Edit', '-+', '', $id );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully update amount !',
        ] );
    }

    public function RemoveUserBalance( $id ) {
        $user = User::find( $id );

        if ( $user->balance < request( 'amount' ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Insufficient Balance !',
            ] );
        }

        $user->decrement( 'balance', request( 'amount' ) );
        $trxid = uniqid();
        $type  = "recharge";

        PaymentHistoryService::store( uniqid(), request( 'amount' ), 'Manually by admin', 'Less', '-', '', $id );

        return response()->json( [
            'status'  => 200,
            'message' => 'Successfully less amount ' . request( 'amount' ) . ' ! ',
        ] );
    }
}
