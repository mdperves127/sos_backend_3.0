<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Withdraw;
use App\Services\PaymentHistoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WithdrawController extends Controller {
    //
    function index() {
        if ( checkpermission( 'withdraw' ) != 1 ) {
            return $this->permissionmessage();
        }

        $search   = request( 'search' );
        $withdraw = Withdraw::query()
            ->latest()
            ->when( request( 'status' ) == 'pending', function ( $q ) {
                return $q->where( 'status', 'pending' );
            } )
            ->when( request( 'status' ) == 'success', function ( $q ) {
                return $q->where( 'status', 'success' );
            } )
            ->when( request( 'type' ) == 'vendor', function ( $q ) {
                return $q->where( 'role', '2' );
            } )
            ->when( request( 'type' ) == 'affiliate', function ( $q ) {
                return $q->where( 'role', '3' );
            } )
            ->when( request( 'type' ) == 'user', function ( $q ) {
                return $q->where( 'role', '4' );
            } )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->where( 'uniqid', 'like', "%{$search}%" );
            } )
            ->when( request( 'from' ) && request( 'to' ), function ( $query ) {
                $fromDate = Carbon::parse( request( 'from' ) );
                $toDate   = Carbon::parse( request( 'to' ) )->addDay( 1 );
                $query->whereBetween( 'created_at', [$fromDate, $toDate] );
            } )
            ->with( 'user:id,name' )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response( [
            'status'  => 200,
            'message' => $withdraw,
        ] );
    }

    function paid( Request $request, $id ) {
        $validator = Validator::make( $request->all(), [
            'admin_transition_id' => 'required',
            'admin_bank_name'     => 'required',
            'text'                => 'required',

        ] );
        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 401,
                'message' => $validator->messages(),
            ] );
        }

        $withdraw                      = Withdraw::find( $id );
        $withdraw->admin_transition_id = $request->admin_transition_id;

        if ( $request->file( 'admin_screenshot' ) ) {
            $admin_screenshot           = fileUpload( $request->file( 'admin_screenshot' ), 'uploads/admin/screenshort' );
            $withdraw->admin_screenshot = $admin_screenshot;
        }

        $withdraw->admin_bank_name = $request->admin_bank_name;
        $withdraw->text            = $request->text;
        $withdraw->status          = Status::Success->value;
        $withdraw->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Paid Successfully',
        ] );
    }

    function withdrawcancel( int $id ) {
        $withdraw = Withdraw::query()
            ->where( 'id', $id )
            ->where( 'status', '!=', 'success' )
            ->first();

        if ( !$withdraw ) {
            return responsejson( 'Not found', 'fail' );
        }
        if ( request( 'reason' ) != '' ) {
            $withdraw->reason = request( 'reason' );
        }

        PaymentHistoryService::store( uniqid(), $withdraw->amount, 'My wallet', 'Withdraw refund', '+', '', $withdraw->user_id );
        User::find( $withdraw->user_id )->increment( 'balance', $withdraw->amount );

        $withdraw->status = 'reject';
        $withdraw->save();

        return responsejson( 'Reject successfully!' );
    }
}
