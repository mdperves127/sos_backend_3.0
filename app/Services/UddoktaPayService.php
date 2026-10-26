<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * UddoktaPay gateway for tenant storefront product checkout.
 *
 * @see https://uddoktapay.readme.io/reference/overview
 * @see https://uddoktapay.readme.io/reference/create-charge-api-guideline
 * @see https://uddoktapay.readme.io/reference/verify-payment-api-guideline
 */
class UddoktaPayService
{
    /**
     * Request-scoped tenant credentials for product checkout (no platform fallback).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $runtimeCredentials = null;

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function useRuntimeCredentials( array $credentials ): void
    {
        self::$runtimeCredentials = $credentials;
    }

    public static function clearRuntimeCredentials(): void
    {
        self::$runtimeCredentials = null;
    }

    public static function hasRuntimeCredentials(): bool
    {
        return is_array( self::$runtimeCredentials ) && self::$runtimeCredentials !== [];
    }

    public static function gateway(
        float $amount,
        string $trxId,
        array $customer,
        string $successUrl,
        string $cancelUrl,
        ?string $webhookUrl = null,
        array $metadata = []
    ): object {
        self::ensureConfigured();

        $payload = [
            'full_name'    => (string) ( $customer['name'] ?? 'Customer' ),
            'email'        => (string) ( $customer['email'] ?? 'customer@example.com' ),
            'amount'       => (string) round( $amount, 2 ),
            'metadata'     => array_merge( [
                'trxid' => $trxId,
            ], $metadata ),
            'redirect_url' => $successUrl,
            'return_type'  => 'GET',
            'cancel_url'   => $cancelUrl,
        ];

        if ( $webhookUrl ) {
            $payload['webhook_url'] = $webhookUrl;
        }

        $response = Http::withHeaders( [
            'RT-UDDOKTAPAY-API-KEY' => self::apiKey(),
            'Accept'               => 'application/json',
            'Content-Type'         => 'application/json',
        ] )
            ->timeout( 30 )
            ->post( self::endpoint( 'checkout-v2' ), $payload );

        $data = $response->json() ?? [];

        if ( ! $response->successful() || empty( $data['payment_url'] ) ) {
            Log::error( 'UddoktaPay create charge failed.', [
                'status' => $response->status(),
                'body'   => $data,
                'trx'    => $trxId,
            ] );

            throw new RuntimeException(
                (string) ( $data['message'] ?? 'UddoktaPay payment initialization failed.' )
            );
        }

        return (object) [
            'result'      => 'true',
            'payment_url' => $data['payment_url'],
            'invoice_id'  => $data['invoice_id'] ?? null,
        ];
    }

    public static function verifyPayment( string $invoiceId ): array
    {
        self::ensureConfigured();

        $response = Http::withHeaders( [
            'RT-UDDOKTAPAY-API-KEY' => self::apiKey(),
            'Accept'               => 'application/json',
            'Content-Type'         => 'application/json',
        ] )
            ->timeout( 30 )
            ->post( self::endpoint( 'verify-payment' ), [
                'invoice_id' => $invoiceId,
            ] );

        $data = $response->json() ?? [];

        if ( ! $response->successful() ) {
            Log::error( 'UddoktaPay verify failed.', [
                'status'     => $response->status(),
                'body'       => $data,
                'invoice_id' => $invoiceId,
            ] );

            throw new RuntimeException(
                (string) ( $data['message'] ?? 'UddoktaPay payment verification failed.' )
            );
        }

        return $data;
    }

    public static function isSuccessful( array $verification ): bool
    {
        return strtoupper( (string) ( $verification['status'] ?? '' ) ) === 'COMPLETED';
    }

    public static function resolveInvoiceId(): ?string
    {
        return request( 'invoice_id' )
            ?? request( 'invoiceId' )
            ?? request()->input( 'invoice_id' )
            ?? null;
    }

    public static function isValidWebhookApiKey( ?string $headerKey ): bool
    {
        $expected = self::apiKey();

        return $expected !== '' && is_string( $headerKey ) && hash_equals( $expected, $headerKey );
    }

    public static function tenantCallbackUrl( string $tenantId, string $path ): string
    {
        $base = rtrim( (string) config( 'services.uddoktapay.callback_base_url', config( 'app.url' ) ), '/' );

        return $base . '/api/uddoktapay/' . rawurlencode( $tenantId ) . '/' . ltrim( $path, '/' );
    }

    private static function endpoint( string $path ): string
    {
        return rtrim( self::baseUrl(), '/' ) . '/api/' . ltrim( $path, '/' );
    }

    private static function baseUrl(): string
    {
        if ( self::hasRuntimeCredentials() ) {
            return rtrim( (string) ( self::$runtimeCredentials['base_url'] ?? '' ), '/' );
        }

        return (string) config( 'services.uddoktapay.base_url', 'https://sandbox.uddoktapay.com' );
    }

    private static function apiKey(): string
    {
        if ( self::hasRuntimeCredentials() ) {
            return trim( (string) ( self::$runtimeCredentials['api_key'] ?? '' ) );
        }

        return trim( (string) config( 'services.uddoktapay.api_key', '' ) );
    }

    private static function ensureConfigured(): void
    {
        if ( self::apiKey() === '' || self::baseUrl() === '' ) {
            throw new RuntimeException(
                self::hasRuntimeCredentials()
                    ? 'UddoktaPay is not configured for this store. Update payment gateway settings.'
                    : 'UddoktaPay is not configured. Set tenant UddoktaPay credentials in payment gateway settings.'
            );
        }
    }
}
