<?php

namespace App\Http\Controllers;

use App\Helper\RedirectHelper;
use App\Services\EpsPaymentCompletionService;
use App\Services\EpsPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicEpsController extends Controller
{
    /**
     * EPS browser return URL.
     * Credits recharge / subscription / renew after verifying with EPS, then redirects to dashboard.
     */
    public function complete( Request $request )
    {
        $merchantTransactionId = EpsPaymentService::resolveTransactionId()
            ?: $request->query( 'merchant_transaction_id' )
            ?: $request->input( 'merchant_transaction_id' );

        $callback = (string) ( $request->query( 'callback', $request->input( 'callback', '' ) ) );
        $status   = strtolower( (string) ( $request->query( 'Status', $request->query( 'status', '' ) ) ) );
        $tenantId = $request->query( 'tenant', $request->input( 'tenant' ) );

        $dashboard = $this->dashboardUrl( $tenantId );

        if ( in_array( $callback, ['fail', 'cancel'], true ) || in_array( $status, ['fail', 'failed', 'cancel', 'cancelled'], true ) ) {
            $message = in_array( $callback, ['cancel'], true ) || str_contains( $status, 'cancel' )
                ? 'Payment cancelled'
                : 'Payment failed';

            return redirect()->away( $dashboard . '?message=' . urlencode( $message ) );
        }

        if ( ! $merchantTransactionId ) {
            return redirect()->away( $dashboard . '?message=' . urlencode( 'Missing MerchantTransactionId' ) );
        }

        try {
            $redirectUrl = app( EpsPaymentCompletionService::class )
                ->completeByTransactionId( (string) $merchantTransactionId, $callback !== '' ? $callback : null );
        } catch ( Throwable $e ) {
            Log::error( 'Public EPS complete failed', [
                'trx'   => $merchantTransactionId,
                'error' => $e->getMessage(),
            ] );

            return redirect()->away( $dashboard . '?message=' . urlencode( $e->getMessage() ) );
        }

        return redirect()->away( $redirectUrl );
    }

    private function dashboardUrl( mixed $tenantId ): string
    {
        if ( $tenantId ) {
            return rtrim( RedirectHelper::getTenantRedirectUrl( (string) $tenantId ), '/' ) . '/dashboard';
        }

        return rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/dashboard';
    }
}
