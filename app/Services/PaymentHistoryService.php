<?php

namespace App\Services;

use App\Models\PaymentHistory;
use App\Models\User;

/**
 * Class PaymentHistoryService.
 */
class PaymentHistoryService
{
    static function store($trxid,$amount,$payment_method,$transition_type,$balance_type,$coupon,$userid){
        $user = User::find($userid);

        $user->paymenthistories()->create([
            'trxid'=>$trxid,
            'amount'=>$amount,
            'payment_method'=>$payment_method,
            'transition_type'=>$transition_type,
            'balance_type'=> $balance_type,
            'coupon'=>$coupon,
        ]);
    }
}
