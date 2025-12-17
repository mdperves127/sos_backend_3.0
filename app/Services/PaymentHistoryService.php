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
        $tenant = Tenant::on('mysql')->find($tenant_id);

        if ($tenant) {
            // If we're in tenant context, create directly in tenant database without using relationship
            // The relationship tries to use tenant_id which doesn't exist in tenant DB tables
            if (tenant()) {
                // We're in tenant context - create payment history in tenant database
                // Use authenticated user's ID if available, otherwise use 1 as default
                $user_id = auth()->check() ? auth()->user()->id : 1;

                PaymentHistory::create([
                    'trxid' => $trxid,
                    'amount' => $amount,
                    'payment_method' => $payment_method,
                    'transition_type' => $transition_type,
                    'balance_type' => $balance_type,
                    'coupon' => $coupon,
                    'user_id' => $user_id,
                ]);
            } else {
                // We're in main context - use relationship which stores in main DB with tenant_id
                $tenant->paymenthistories()->create([
                    'trxid' => $trxid,
                    'amount' => $amount,
                    'payment_method' => $payment_method,
                    'transition_type' => $transition_type,
                    'balance_type' => $balance_type,
                    'coupon' => $coupon,
                ]);
            }
        } else {
            // It's a user, not a tenant
            $user = User::find($tenant_id);
            if ($user) {
                // If in tenant context, create in tenant DB directly
                if (tenant()) {
                    PaymentHistory::create([
                        'trxid' => $trxid,
                        'amount' => $amount,
                        'payment_method' => $payment_method,
                        'transition_type' => $transition_type,
                        'balance_type' => $balance_type,
                        'coupon' => $coupon,
                        'user_id' => $tenant_id,
                    ]);
                } else {
                    // In main context, use relationship
                    $user->paymenthistories()->create([
                        'trxid' => $trxid,
                        'amount' => $amount,
                        'payment_method' => $payment_method,
                        'transition_type' => $transition_type,
                        'balance_type' => $balance_type,
                        'coupon' => $coupon,
                        'user_id' => $tenant_id,
                    ]);
                }
            }
        }
    }
}
