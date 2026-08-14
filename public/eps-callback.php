<?php

/**
 * Physical EPS return URL entrypoint.
 * Lives as a real file so SPA/frontend catch-all routes cannot swallow the callback.
 *
 * Example:
 *   /eps-callback.php?callback=recharge&tenant=auga&MerchantTransactionId=xxx&Status=Success
 */

use App\Services\EpsPaymentCompletionService;
use App\Services\EpsPaymentService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define( 'LARAVEL_START', microtime( true ) );

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel  = $app->make( Kernel::class );
$request = Request::capture();
$kernel->bootstrap();

try {
    $merchantTransactionId = EpsPaymentService::resolveTransactionId();

    if ( ! $merchantTransactionId ) {
        http_response_code( 400 );
        echo 'Missing MerchantTransactionId';
        exit;
    }

    $status = strtolower( (string) ( $request->query( 'Status', $request->query( 'status', '' ) ) ) );
    $hint   = (string) $request->query( 'callback', $request->query( 'type', '' ) );

    if ( in_array( $hint, ['fail', 'cancel'], true ) || in_array( $status, ['fail', 'failed', 'cancel', 'cancelled'], true ) ) {
        $message  = in_array( $hint, ['cancel'], true ) || str_contains( $status, 'cancel' )
            ? 'Payment cancelled'
            : 'Payment failed';
        $redirect = rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/dashboard?message=' . rawurlencode( $message );
        header( 'Location: ' . $redirect );
        exit;
    }

    if ( $status !== '' && $status !== 'success' ) {
        $redirect = rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/dashboard?message=' . rawurlencode( 'Payment ' . $status );
        header( 'Location: ' . $redirect );
        exit;
    }

    $redirectUrl = $app->make( EpsPaymentCompletionService::class )
        ->completeByTransactionId( $merchantTransactionId, $hint ?: null );

    header( 'Location: ' . $redirectUrl );
    exit;
} catch ( Throwable $e ) {
    \Illuminate\Support\Facades\Log::error( 'EPS callback failed', [
        'error' => $e->getMessage(),
        'query' => $request->query(),
    ] );

    $redirect = rtrim( (string) config( 'app.redirecturl' ), '/' )
        . '/dashboard?message=' . rawurlencode( 'Payment verification failed: ' . $e->getMessage() );
    header( 'Location: ' . $redirect );
    exit;
}
