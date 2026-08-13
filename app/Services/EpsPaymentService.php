<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Carbon\Carbon;

class EpsPaymentService
{
    private static ?string $cachedToken = null;

    private static ?Carbon $cachedTokenExpiresAt = null;
    public static function gateway( float $price, string $traxId, string $type, string $successUrl, string $tenantType ): object
    {
        self::ensureConfigured();

        $user = Auth::user();
        [$failUrl, $cancelUrl] = self::callbackUrlsFromSuccess( $successUrl );

        $initialize = self::initializePayment( [
            'merchant_transaction_id' => $traxId,
            'customer_order_id'       => $traxId,
            'total_amount'            => $price,
            'success_url'             => $successUrl,
            'fail_url'                => $failUrl,
            'cancel_url'              => $cancelUrl,
            'customer_name'           => $user?->name ?? 'Customer',
            'customer_email'          => $user?->email ?? 'customer@example.com',
            'customer_phone'          => $user?->number ?? '01700000000',
            'product_name'            => $type,
            'value_a'                 => $type,
            'value_b'                 => $tenantType,
        ] );

        return (object) [
            'result'      => 'true',
            'payment_url' => $initialize['RedirectURL'],
        ];
    }

    public static function initializePayment( array $params ): array
    {
        $merchantTransactionId = self::normalizeTransactionId( (string) $params['merchant_transaction_id'] );
        $token                 = self::getToken();
        $productName           = (string) ( $params['product_name'] ?? 'Payment' );
        $totalAmount           = (float) $params['total_amount'];
        $productList           = $params['product_list'] ?? [];

        if ( $productList === [] ) {
            $productList = [[
                'ProductName'     => $productName,
                'NoOfItem'        => 1,
                'ProductPrice'    => $totalAmount,
                'ProductProfile'  => 'general',
                'ProductCategory' => 'general',
            ]];
        }

        $body = [
            'merchantId'            => config( 'services.eps.merchant_id' ),
            'storeId'               => config( 'services.eps.store_id' ),
            'CustomerOrderId'       => (string) ( $params['customer_order_id'] ?? $merchantTransactionId ),
            'merchantTransactionId' => $merchantTransactionId,
            // EPS: 1=Web, 2=Android, 3=iOS
            'transactionTypeId'     => (int) config( 'services.eps.transaction_type_id', 1 ),
            'financialEntityId'     => 0,
            'transitionStatusId'    => 0,
            'totalAmount'           => $totalAmount,
            'ipAddress'             => request()->ip() ?? '0.0.0.0',
            'version'               => '1',
            'successUrl'            => $params['success_url'],
            'failUrl'               => $params['fail_url'],
            'cancelUrl'             => $params['cancel_url'],
            'customerName'          => $params['customer_name'] ?? 'Customer',
            'customerEmail'         => $params['customer_email'] ?? 'customer@example.com',
            'CustomerAddress'       => $params['customer_address'] ?? 'Dhaka',
            'CustomerAddress2'      => '',
            'CustomerCity'          => $params['customer_city'] ?? 'Dhaka',
            'CustomerState'         => $params['customer_state'] ?? 'Dhaka',
            'CustomerPostcode'      => $params['customer_postcode'] ?? '1200',
            'CustomerCountry'       => $params['customer_country'] ?? 'BD',
            'CustomerPhone'         => $params['customer_phone'] ?? '01700000000',
            'ShipmentName'          => '',
            'ShipmentAddress'       => '',
            'ShipmentAddress2'      => '',
            'ShipmentCity'          => '',
            'ShipmentState'         => '',
            'ShipmentPostcode'      => '',
            'ShipmentCountry'       => '',
            'ValueA'                => (string) ( $params['value_a'] ?? '' ),
            'ValueB'                => (string) ( $params['value_b'] ?? '' ),
            'ValueC'                => (string) ( $params['value_c'] ?? '' ),
            'ValueD'                => (string) ( $params['value_d'] ?? '' ),
            'ShippingMethod'        => 'NO',
            'NoOfItem'              => (string) max( 1, count( $productList ) ),
            'ProductName'           => $productName,
            'ProductProfile'        => 'general',
            'ProductCategory'       => 'general',
            'ProductList'           => $productList,
        ];

        $response = Http::timeout( 30 )
            ->withToken( $token )
            ->withHeaders( ['x-hash' => self::generateHash( $merchantTransactionId )] )
            ->post( self::endpoint( 'initialize' ), $body );

        $data = $response->json();

        if ( ! $response->successful() || ! empty( $data['ErrorMessage'] ) || empty( $data['RedirectURL'] ) ) {
            Log::error( 'EPS initialize failed.', [
                'status'   => $response->status(),
                'sandbox'  => (bool) config( 'services.eps.sandbox' ),
                'endpoint' => self::endpoint( 'initialize' ),
                'response' => $data,
                'body'     => $response->body(),
            ] );

            throw new RuntimeException( self::formatEpsError(
                $data,
                $response->status(),
                $response->body(),
                'EPS payment initialization failed.'
            ) );
        }

        return $data;
    }

    public static function verifyTransaction( string $merchantTransactionId ): array
    {
        $token    = self::getToken();
        $response = Http::timeout( 30 )
            ->withToken( $token )
            ->withHeaders( ['x-hash' => self::generateHash( $merchantTransactionId )] )
            ->get( self::endpoint( 'verify' ), [
                'merchantTransactionId' => $merchantTransactionId,
            ] );

        $data = $response->json();

        if ( ! $response->successful() || ! empty( $data['ErrorMessage'] ) ) {
            Log::error( 'EPS verify failed.', [
                'merchant_transaction_id' => $merchantTransactionId,
                'status'                  => $response->status(),
                'response'                => $data,
            ] );

            throw new RuntimeException( $data['ErrorMessage'] ?? 'EPS transaction verification failed.' );
        }

        return $data;
    }

    public static function isSuccessful( array $verification ): bool
    {
        return strtolower( (string) ( $verification['Status'] ?? '' ) ) === 'success';
    }

    public static function resolveTransactionId(): ?string
    {
        return request( 'merchantTransactionId' )
            ?? request( 'MerchantTransactionId' )
            ?? request( 'mer_txnid' );
    }

    public static function paymentSuccessUrl( string $callbackPath ): string
    {
        if ( function_exists( 'tenant' ) && tenant() ) {
            return self::tenantCallbackUrl( $callbackPath );
        }

        return self::centralCallbackUrl( $callbackPath );
    }

    public static function centralCallbackUrl( string $path ): string
    {
        return url( 'api/user/aaparpay/' . ltrim( $path, '/' ) );
    }

    public static function tenantCallbackUrl( string $path ): string
    {
        return rtrim( request()->getSchemeAndHttpHost(), '/' ) . '/api/aaparpay/' . ltrim( $path, '/' );
    }

    private static function callbackUrlsFromSuccess( string $successUrl ): array
    {
        if ( str_contains( $successUrl, '/api/user/aaparpay/' ) ) {
            return [
                self::centralCallbackUrl( 'fail' ),
                self::centralCallbackUrl( 'cancel' ),
            ];
        }

        $base = preg_replace( '#/[^/]+$#', '', $successUrl );

        return [
            $base . '/fail',
            $base . '/cancel',
        ];
    }

    private static function getToken(): string
    {
        if ( self::$cachedToken && self::$cachedTokenExpiresAt && now()->lt( self::$cachedTokenExpiresAt ) ) {
            return self::$cachedToken;
        }

        self::ensureConfigured();

        $username = config( 'services.eps.username' );
        $password = config( 'services.eps.password' );

        $response = Http::timeout( 30 )
            ->withHeaders( ['x-hash' => self::generateHash( $username )] )
            ->post( self::endpoint( 'token' ), [
                'userName' => $username,
                'password' => $password,
            ] );

        $data = $response->json();

        if ( ! $response->successful() || empty( $data['token'] ) ) {
            Log::error( 'EPS token request failed.', [
                'status'   => $response->status(),
                'response' => $data,
            ] );

            $message = self::formatEpsError(
                $data,
                $response->status(),
                $response->body(),
                'Unable to authenticate with EPS.'
            );

            if ( str_contains( strtolower( $message ), 'error occured' ) ) {
                $message .= ' Check that EPS_USERNAME, EPS_PASSWORD, EPS_HASH_KEY, EPS_MERCHANT_ID, and EPS_STORE_ID all belong to the same EPS merchant account, and EPS_SANDBOX matches live/sandbox credentials.';
            }

            throw new RuntimeException( $message );
        }

        self::$cachedToken          = $data['token'];
        self::$cachedTokenExpiresAt = ! empty( $data['expireDate'] )
            ? Carbon::parse( $data['expireDate'] )->subMinute()
            : now()->addMinutes( 50 );

        return self::$cachedToken;
    }

    private static function generateHash( string $value ): string
    {
        $hashKey = trim( (string) config( 'services.eps.hash_key' ), " \t\n\r\0\x0B\"'" );

        return base64_encode( hash_hmac( 'sha512', $value, $hashKey, true ) );
    }

    private static function normalizeTransactionId( string $transactionId ): string
    {
        $transactionId = trim( $transactionId );

        // EPS requires merchantTransactionId length >= 10
        if ( strlen( $transactionId ) < 10 ) {
            $transactionId = str_pad( $transactionId, 10, '0', STR_PAD_LEFT );
        }

        return $transactionId;
    }

    private static function formatEpsError( mixed $data, int $status, ?string $rawBody, string $fallback ): string
    {
        $message = null;

        if ( is_array( $data ) ) {
            $message = $data['ErrorMessage']
                ?? $data['errorMessage']
                ?? $data['Message']
                ?? $data['message']
                ?? $data['title']
                ?? null;

            if ( blank( $message ) && ! empty( $data['ErrorCode'] ) ) {
                $message = 'EPS error code: ' . $data['ErrorCode'];
            }

            if ( blank( $message ) && ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
                $message = collect( $data['errors'] )->flatten()->filter()->implode( '; ' );
            }
        }

        if ( blank( $message ) && filled( $rawBody ) ) {
            $trimmed = trim( $rawBody );
            $message = strlen( $trimmed ) > 300 ? ( substr( $trimmed, 0, 300 ) . '...' ) : $trimmed;
        }

        $message = filled( $message ) ? (string) $message : $fallback;
        $mode    = config( 'services.eps.sandbox' ) ? 'sandbox' : 'live';

        return $message . " [EPS {$mode}, HTTP {$status}]";
    }

    private static function ensureConfigured(): void
    {
        $required = [
            'EPS_USERNAME'    => config( 'services.eps.username' ),
            'EPS_PASSWORD'    => config( 'services.eps.password' ),
            'EPS_HASH_KEY'    => config( 'services.eps.hash_key' ),
            'EPS_MERCHANT_ID' => config( 'services.eps.merchant_id' ),
            'EPS_STORE_ID'    => config( 'services.eps.store_id' ),
        ];

        $missing = array_keys( array_filter( $required, fn ( $value ) => blank( $value ) ) );

        if ( $missing !== [] ) {
            throw new RuntimeException(
                'EPS payment gateway is not configured. Add these to .env: ' . implode( ', ', $missing )
            );
        }
    }

    private static function endpoint( string $name ): string
    {
        $base = config( 'services.eps.sandbox' )
            ? 'https://sandboxpgapi.eps.com.bd/v1'
            : 'https://pgapi.eps.com.bd/v1';

        return match ( $name ) {
            'token'      => $base . '/Auth/GetToken',
            'initialize' => $base . '/EPSEngine/InitializeEPS',
            'verify'     => $base . '/EPSEngine/CheckMerchantTransactionStatus',
            default      => throw new RuntimeException( 'Unknown EPS endpoint.' ),
        };
    }
}
