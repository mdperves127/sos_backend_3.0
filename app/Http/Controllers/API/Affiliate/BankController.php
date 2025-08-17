<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\Settings;

class BankController extends Controller {
    //
    function index() {
        return response()->json( [
            'status'   => 200,
            'message'  => Bank::latest()->get(),
            'settings' => Settings::select( 'minimum_withdraw', 'withdraw_charge', 'withdraw_charge_status' )->first(),
        ] );

    }

}
