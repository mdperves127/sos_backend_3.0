<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\RechargeRequest;
use App\Models\PaymentStore;
use App\Services\AamarPayService;

class RechargeController extends Controller
{
    function recharge( RechargeRequest $request ) {
        $validateData            = $request->validated();
        $validateData['tenant_id'] = tenant()->id;
        $total_amount = $validateData['amount'];

        $trxid      = uniqid();
        $type       = "recharge";
        $successurl = url( 'api/aaparpay/recharge-success-for-us' );

        // $validateData['extra_charge'] = number_format( $extra_charge, 2 ); //For extra charge
        PaymentStore::on('mysql')->create( [
            'payment_gateway'         => 'aamarpay',
            'trxid'                   => $trxid,
            'payment_type'            => 'recharge',
            'info'                    => $validateData,
            'customer_requirement_id' => 0,
        ] );
        // return 2;

        return AamarPayService::gateway( $total_amount, $trxid, $type, $successurl, $request->tenant_type );
    }


}
