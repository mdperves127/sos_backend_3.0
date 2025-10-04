<?php

namespace App\Services;

use App\Models\PaymentHistory;
use App\Models\User;
use App\Models\Tenant;

/**
 * Class PaymentHistoryService.
 */
class PaymentHistoryService
{
    static function store($trxid, $amount, $payment_method, $transition_type, $balance_type, $coupon, $tenant_id) {
        $tenant = Tenant::find($tenant_id);
        if ($tenant) {
            $tenant->paymenthistories()->create([
                'trxid' => $trxid,
                'amount' => $amount,
                'payment_method' => $payment_method,
                'transition_type' => $transition_type,
                'balance_type' => $balance_type,
                'coupon' => $coupon,
            ]);
        }
    }
}
