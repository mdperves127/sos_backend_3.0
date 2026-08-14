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
     * - Browser hit (no JSON Accept): credit + redirect to dashboard
     * - fetch() from eps-return.html: credit + JSON { redirect_url }
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
            return $this->respond( $request, false, 'Missing MerchantTransactionId', $dashboard . '?message=' . urlencode( 'Missing MerchantTransactionId' ), 422 );
        }

        try {
            $redirectUrl = app( EpsPaymentCompletionService::class )
                ->completeByTransactionId( (string) $merchantTransactionId, $callback !== '' ? $callback : null );
        } catch ( Throwable $e ) {
            Log::error( 'Public EPS complete failed', [
                'trx'   => $merchantTransactionId,
                'error' => $e->getMessage(),
            ] );

            return $this->respond( $request, false, $e->getMessage(), $dashboard . '?message=' . urlencode( $e->getMessage() ), 422 );
        }

        return $this->respond( $request, true, 'Payment completed successfully.', $redirectUrl );
    }

    private function respond( Request $request, bool $ok, string $message, ?string $redirectUrl, int $status = 200 )
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
