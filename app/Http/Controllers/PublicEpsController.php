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
     * EPS return completer.
     * Browser lands here (directly or via affsell.com → API redirect), payment is credited,
     * then user is sent to tenant subdomain/custom-domain /dashboard.
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

            return $this->respond( $request, false, $message, $dashboard . '?message=' . urlencode( $message ) );
        }

        if ( ! $merchantTransactionId ) {
            return $this->respond(
                $request,
                false,
                'Missing MerchantTransactionId',
                $dashboard . '?message=' . urlencode( 'Missing MerchantTransactionId' ),
                422
            );
        }

        try {
            $redirectUrl = app( EpsPaymentCompletionService::class )
                ->completeByTransactionId( (string) $merchantTransactionId, $callback !== '' ? $callback : null );
        } catch ( Throwable $e ) {
            Log::error( 'Public EPS complete failed', [
                'trx'   => $merchantTransactionId,
                'error' => $e->getMessage(),
            ] );

            return $this->respond(
                $request,
                false,
                $e->getMessage(),
                $dashboard . '?message=' . urlencode( $e->getMessage() ),
                422
            );
        }

        return $this->respond( $request, true, 'Payment completed successfully.', $redirectUrl );
    }

    /**
     * Background poller (no auth). Kicked after payment initialize.
     * Completes recharge/renew/subscription as soon as EPS reports Success.
     */
    public function pollComplete( Request $request )
    {
        $merchantTransactionId = (string) (
            $request->query( 'merchant_transaction_id' )
            ?: $request->input( 'merchant_transaction_id' )
            ?: ''
        );
        $callback = (string) ( $request->query( 'callback', $request->input( 'callback', 'recharge' ) ) );
        $sig      = (string) ( $request->query( 'sig', $request->input( 'sig', '' ) ) );
        $depth    = (int) $request->query( 'depth', $request->input( 'depth', 0 ) );

        if ( $merchantTransactionId === ''
            || ! hash_equals( EpsPaymentService::pollSignature( $merchantTransactionId, $callback ), $sig ) ) {
            return response( 'forbidden', 403 );
        }

        ignore_user_abort( true );
        @set_time_limit( 130 );

        for ( $i = 0; $i < 20; $i++ ) {
            if ( $i > 0 ) {
                sleep( 3 );
            }

            try {
                if ( app( EpsPaymentCompletionService::class )
                    ->tryCompleteIfSuccessful( $merchantTransactionId, $callback ) ) {
                    return response( 'completed', 200 );
                }
            } catch ( Throwable $e ) {
                Log::debug( 'EPS poll-complete attempt', [
                    'trx'   => $merchantTransactionId,
                    'try'   => $i,
                    'depth' => $depth,
                    'error' => $e->getMessage(),
                ] );
            }
        }

        // Still pending — chain another process (covers slow bank confirmations).
        if ( $depth < 4 ) {
            EpsPaymentService::firePollRequest( $merchantTransactionId, $callback, $depth + 1 );
        }

        return response( 'pending', 200 );
    }

    private function respond( Request $request, bool $ok, string $message, ?string $redirectUrl, int $status = 200 )
    {
        $wantsJson = $request->query( 'format' ) === 'json'
            || ( $request->expectsJson() && ! $request->isMethod( 'GET' ) );

        // Prefer browser redirect so user lands on tenant dashboard.
        if ( ! $wantsJson && $redirectUrl ) {
            return redirect()->away( $redirectUrl );
        }

        if ( $wantsJson ) {
            return response()->json( [
                'status'  => $ok ? 200 : $status,
                'message' => $message,
                'data'    => [
                    'redirect_url' => $redirectUrl,
                ],
            ], $ok ? 200 : $status );
        }

        return redirect()->away( $redirectUrl ?: $this->dashboardUrl( null ) );
    }

    private function dashboardUrl( mixed $tenantId ): string
    {
        if ( $tenantId ) {
            return rtrim( RedirectHelper::getTenantRedirectUrl( (string) $tenantId ), '/' ) . '/dashboard';
        }

        return rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/dashboard';
    }
}
