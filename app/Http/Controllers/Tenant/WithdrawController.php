<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use App\Models\Withdraw;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WithdrawController extends Controller {

    public function index() {
        $search   = request( 'search' );
        $withdraw = Withdraw::on( 'mysql' )
            ->where( 'user_id', tenant()->id )
            ->latest()
            ->when( request( 'status' ) == 'success', function ( $q ) {
                return $q->where( 'status', 'success' );
            } )
            ->when( request( 'status' ) == 'pending', function ( $q ) {
                return $q->where( 'status', 'pending' );
            } )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->where( 'uniqid', 'like', "%{$search}%" );
            } )
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'message' => $withdraw,
        ] );
    }

    function withdraw( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'amount'       => ['required', 'numeric', 'min:200'],
            'bank_name'    => ['required'],
            'ac_or_number' => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 401,
                'message' => $validator->messages(),
            ] );
        }

        $setting = Settings::on('mysql')->first();

        if ( $setting->minimum_withdraw > $request->amount ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Minimum withdraw amount is ' . $setting->minimum_withdraw . ' !',
            ] );
        }

        if ( $setting->withdraw_charge_status == "on" ) {
            $charge = extraCharge( $request->amount, $setting->withdraw_charge );
        } else {
            $charge = 0;
        }

        if ( tenant()->balance >= $request->amount + $charge ) {

            Withdraw::on( 'mysql' )->create( [
                'user_id'      => auth()->id(),
                'amount'       => $request->amount,
                'bank_name'    => $request->bank_name,
                'ac_or_number' => $request->ac_or_number,
                'holder_name'  => $request->holder_name,
                'branch_name'  => $request->branch_name,
                'role'         => auth()->user()->role_as,
                'uniqid'       => uniqid(),
                'charge'       => $charge,
            ] );


            $tenant          = tenant();
            // dd($tenant);
            $tenant->balance = $setting->withdraw_charge_status == "on" ? ( $tenant->balance - $request->amount ) - $charge : ( $tenant->balance - $request->amount );
            $tenant->save();

            return response()->json( [
                'status'  => 200,
                'message' => 'Withdraw successfully!',
            ] );
        } else {
            return response()->json( [
                'status'  => 200,
                'message' => 'Balance not available!',
            ] );
        }
    }
}
