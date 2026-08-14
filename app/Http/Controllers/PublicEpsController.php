<?php

namespace App\Http\Controllers;

use App\Services\EpsPaymentCompletionService;
use App\Services\EpsPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicEpsController extends Controller
{
    /**
     * Public EPS return completer (called from eps-return.html on the frontend domain).
     * Always verifies the transaction with EPS before crediting.
     */
    public function complete( Request $request )
    {
        $merchantTransactionId = EpsPaymentService::resolveTransactionId()
            ?: $request->query( 'merchant_transaction_id' )
            ?: $request->input( 'merchant_transaction_id' );

        $callback = (string) ( $request->query( 'callback', $request->input( 'callback', '' ) ) );
        $status   = strtolower( (string) ( $request->query( 'Status', $request->query( 'status', '' ) ) ) );

        if ( in_array( $callback, ['fail', 'cancel'], true ) || in_array( $status, ['fail', 'failed', 'cancel', 'cancelled'], true ) ) {
            $message = in_array( $callback, ['cancel'], true ) || str_contains( $status, 'cancel' )
                ? 'Payment cancelled'
                : 'Payment failed';

            return $this->finish( $request, false, $message, rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/dashboard?message=' . urlencode( $message ) );
        }

        if ( ! $merchantTransactionId ) {
            return $this->finish( $request, false, 'Missing MerchantTransactionId.', null, 422 );
        }

        try {
            $redirectUrl = app( EpsPaymentCompletionService::class )
                ->completeByTransactionId( (string) $merchantTransactionId, $callback !== '' ? $callback : null );
        } catch ( Throwable $e ) {
            Log::error( 'Public EPS complete failed', [
                'trx'   => $merchantTransactionId,
                'error' => $e->getMessage(),
            ] );

            return $this->finish( $request, false, $e->getMessage(), null, 422 );
        }

        return $this->finish( $request, true, 'Payment completed successfully.', $redirectUrl );
    }

    private function finish( Request $request, bool $ok, string $message, ?string $redirectUrl, int $status = 200 )
    {
        $wantsJson = $request->expectsJson()
            || $request->ajax()
            || str_contains( (string) $request->header( 'Accept' ), 'application/json' )
            || $request->query( 'format' ) === 'json';

        if ( $wantsJson ) {
            return response()->json( [
                'status'  => $ok ? 200 : $status,
                'message' => $message,
                'data'    => [
                    'redirect_url' => $redirectUrl,
                ],
            ], $ok ? 200 : $status );
        }

        if ( $redirectUrl ) {
            return redirect()->away( $redirectUrl );
        }

        return redirect()->away(
            rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/dashboard?message=' . urlencode( $message )
        );
    }
}
