<?php

namespace App\Http\Controllers\Concerns;

use App\Services\EpsPaymentService;

trait HandlesEpsPaymentCallback
{
    protected function verifiedEpsTransaction(): ?array
    {
        $merchantTransactionId = EpsPaymentService::resolveTransactionId();

        if ( ! $merchantTransactionId ) {
            return null;
        }

        try {
            $verification = EpsPaymentService::verifyTransaction( $merchantTransactionId );
        } catch ( \Throwable ) {
            return null;
        }

        if ( ! EpsPaymentService::isSuccessful( $verification ) ) {
            return null;
        }

        return $verification + [
            'mer_txnid'       => $merchantTransactionId,
            'amount_original' => $verification['TotalAmount'] ?? $verification['StoreAmount'] ?? 0,
            'opt_a'           => request( 'ValueA', request( 'valueA' ) ),
            'opt_b'           => request( 'ValueB', request( 'valueB' ) ),
        ];
    }
}
