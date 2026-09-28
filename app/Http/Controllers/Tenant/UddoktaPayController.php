<?php

namespace App\Http\Controllers\Tenant;

use App\Helper\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Models\PaymentStore;
use App\Services\EpsPaymentCompletionService;
use App\Services\UddoktaPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class UddoktaPayController extends Controller
{
    public function productCheckoutSuccess( Request $request )
    {
        $invoiceId = UddoktaPayService::resolveInvoiceId();

        if ( ! $invoiceId ) {
            return redirect( $this->frontendBase() . '?message=' . urlencode( 'Missing UddoktaPay invoice id' ) );
        }

        try {
            $verification = UddoktaPayService::verifyPayment( $invoiceId );
        } catch ( Throwable $e ) {
            return redirect( $this->frontendBase() . '?message=' . urlencode( $e->getMessage() ) );
        }

        if ( ! UddoktaPayService::isSuccessful( $verification ) ) {
            return redirect( $this->frontendBase() . '?message=' . urlencode( 'Payment not completed' ) );
        }

        $trxId = (string) ( $verification['metadata']['trxid'] ?? '' );
        $payment = $trxId !== ''
            ? PaymentStore::on( 'mysql' )->where( 'trxid', $trxId )->first()
            : null;

        if ( ! $payment ) {
            // Fallback: locate by stored invoice id.
            $payment = PaymentStore::on( 'mysql' )
                ->where( 'payment_gateway', 'uddoktapay' )
                ->where( 'status', 'pending' )
                ->get()
                ->first( function ( PaymentStore $row ) use ( $invoiceId ) {
                    $info = is_array( $row->info ) ? $row->info : [];

                    return (string) ( $info['invoice_id'] ?? '' ) === (string) $invoiceId;
                } );
        }

        if ( ! $payment ) {
            return redirect( $this->frontendBase() . '?message=' . urlencode( 'Payment record not found' ) );
        }

        $info = is_array( $payment->info ) ? $payment->info : [];
        $info['invoice_id'] = $invoiceId;
        $info['uddoktapay'] = [
            'transaction_id' => $verification['transaction_id'] ?? null,
            'payment_method' => $verification['payment_method'] ?? null,
            'sender_number'  => $verification['sender_number'] ?? null,
        ];
        $payment->info = $info;
        $payment->save();

        try {
            $url = app( EpsPaymentCompletionService::class )
                ->completeStoredProductCheckout( $payment );
        } catch ( Throwable $e ) {
            Log::error( 'UddoktaPay product checkout completion failed', [
                'invoice_id' => $invoiceId,
                'trx'        => $payment->trxid,
                'error'      => $e->getMessage(),
            ] );

            return redirect( $this->frontendBase( $info ) . '?message=' . urlencode( $e->getMessage() ) );
        }

        return redirect( $url );
    }

    public function productCheckoutCancel()
    {
        return redirect( $this->frontendBase() . '?message=' . urlencode( 'Payment cancelled' ) );
    }

    public function productCheckoutWebhook( Request $request )
    {
        $headerKey = $request->header( 'RT-UDDOKTAPAY-API-KEY' )
            ?? $request->header( 'rt-uddoktapay-api-key' );

        if ( ! UddoktaPayService::isValidWebhookApiKey( $headerKey ) ) {
            return response( 'Unauthorized Action', 401 );
        }

        $payload   = $request->all();
        $invoiceId = (string) ( $payload['invoice_id'] ?? '' );

        if ( $invoiceId === '' ) {
            return response( 'Invalid payload', 400 );
        }

        if ( ! UddoktaPayService::isSuccessful( $payload ) ) {
            return response( 'Ignored non-completed status', 200 );
        }

        $trxId = (string) ( $payload['metadata']['trxid'] ?? '' );
        $payment = $trxId !== ''
            ? PaymentStore::on( 'mysql' )->where( 'trxid', $trxId )->first()
            : null;

        if ( ! $payment ) {
            return response( 'Payment not found', 404 );
        }

        if ( ( $payment->status ?? null ) === 'completed' ) {
            return response( 'Already completed', 200 );
        }

        $info = is_array( $payment->info ) ? $payment->info : [];
        $info['invoice_id'] = $invoiceId;
        $info['uddoktapay'] = [
            'transaction_id' => $payload['transaction_id'] ?? null,
            'payment_method' => $payload['payment_method'] ?? null,
            'sender_number'  => $payload['sender_number'] ?? null,
        ];
        $payment->info = $info;
        $payment->save();

        try {
            app( EpsPaymentCompletionService::class )->completeStoredProductCheckout( $payment );
        } catch ( Throwable $e ) {
            Log::error( 'UddoktaPay webhook checkout failed', [
                'invoice_id' => $invoiceId,
                'error'      => $e->getMessage(),
            ] );

            return response( $e->getMessage(), 500 );
        }

        return response( 'Webhook received successfully', 200 );
    }

    private function frontendBase( ?array $paymentInfo = null ): string
    {
        $tenantId = tenant()?->id
            ?? ( $paymentInfo['storefront_tenant_id'] ?? null )
            ?? ( $paymentInfo['placing_tenant_id'] ?? null )
            ?? ( $paymentInfo['tenant_id'] ?? null );

        return rtrim( RedirectHelper::getPaymentRedirectUrl(
            $tenantId,
            $paymentInfo['return_url'] ?? null
        ), '/' ) . '/';
    }
}
