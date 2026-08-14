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
        $productName           = self::sanitizeText( (string) ( $params['product_name'] ?? 'Payment' ), 100 );
        $totalAmount           = round( (float) $params['total_amount'], 2 );
        $productList           = $params['product_list'] ?? null;

        // Live EPS is strict: empty list is accepted by their API model; malformed items cause HTTP 400.
        if ( ! is_array( $productList ) ) {
            $productList = [];
        }

        $body = [
            'merchantId'            => trim( (string) config( 'services.eps.merchant_id' ) ),
            'storeId'               => trim( (string) config( 'services.eps.store_id' ) ),
            'CustomerOrderId'       => self::normalizeTransactionId( (string) ( $params['customer_order_id'] ?? $merchantTransactionId ) ),
            'merchantTransactionId' => $merchantTransactionId,
            // EPS: 1=Web, 2=Android, 3=iOS
            'transactionTypeId'     => (int) config( 'services.eps.transaction_type_id', 1 ),
            'financialEntityId'     => 0,
            'transitionStatusId'    => 0,
            'totalAmount'           => $totalAmount,
            'ipAddress'             => self::clientIpAddress(),
            'version'               => '1',
            'successUrl'            => self::ensureEpsCompatibleCallbackUrl( (string) $params['success_url'] ),
            'failUrl'               => self::ensureEpsCompatibleCallbackUrl( (string) $params['fail_url'] ),
            'cancelUrl'             => self::ensureEpsCompatibleCallbackUrl( (string) $params['cancel_url'] ),
            'customerName'          => self::sanitizeText( (string) ( $params['customer_name'] ?? 'Customer' ), 100 ),
            'customerEmail'         => self::sanitizeEmail( (string) ( $params['customer_email'] ?? 'customer@example.com' ) ),
            'CustomerAddress'       => self::sanitizeText( (string) ( $params['customer_address'] ?? 'Dhaka' ), 200 ),
            'CustomerAddress2'      => '',
            'CustomerCity'          => self::sanitizeText( (string) ( $params['customer_city'] ?? 'Dhaka' ), 100 ),
            'CustomerState'         => self::sanitizeText( (string) ( $params['customer_state'] ?? 'Dhaka' ), 100 ),
            'CustomerPostcode'      => self::sanitizeText( (string) ( $params['customer_postcode'] ?? '1200' ), 20 ),
            'CustomerCountry'       => 'BD',
            'CustomerPhone'         => self::sanitizeBdPhone( (string) ( $params['customer_phone'] ?? '01700000000' ) ),
            'ShipmentName'          => '',
            'ShipmentAddress'       => '',
            'ShipmentAddress2'      => '',
            'ShipmentCity'          => '',
            'ShipmentState'         => '',
            'ShipmentPostcode'      => '',
            'ShipmentCountry'       => '',
            'ValueA'                => self::sanitizeText( (string) ( $params['value_a'] ?? '' ), 100, true ),
            'ValueB'                => self::sanitizeText( (string) ( $params['value_b'] ?? '' ), 100, true ),
            'ValueC'                => self::sanitizeText( (string) ( $params['value_c'] ?? '' ), 100, true ),
            'ValueD'                => self::sanitizeText( (string) ( $params['value_d'] ?? '' ), 100, true ),
            'ShippingMethod'        => 'NO',
            'NoOfItem'              => '1',
            'ProductName'           => $productName,
            'ProductProfile'        => 'general',
            'ProductCategory'       => 'general',
            'ProductList'           => $productList,
        ];

        $response = Http::timeout( 30 )
            ->acceptJson()
            ->asJson()
            ->withToken( $token )
            ->withHeaders( ['x-hash' => self::generateHash( $merchantTransactionId )] )
            ->post( self::endpoint( 'initialize' ), $body );

        $data = $response->json();

        if ( ! $response->successful() || ! empty( $data['ErrorMessage'] ) || empty( $data['RedirectURL'] ) ) {
            Log::error( 'EPS initialize failed.', [
                'status'   => $response->status(),
                'sandbox'  => (bool) config( 'services.eps.sandbox' ),
                'endpoint' => self::endpoint( 'initialize' ),
                'payload'  => collect( $body )->except( [] )->all(),
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
        // Central PaymentStore flows — complete on Laravel API host, then redirect to tenant dashboard.
        $centralStoreCallbacks = [
            'recharge-success-for-us' => 'recharge',
            'recharge-success'        => 'recharge',
            'subscription-success'    => 'subscription',
            'renew-success'           => 'renew',
        ];

        if ( isset( $centralStoreCallbacks[$callbackPath] ) ) {
            $tenantId = ( function_exists( 'tenant' ) && tenant() ) ? (string) tenant( 'id' ) : null;

            return self::publicCompleteUrl( $centralStoreCallbacks[$callbackPath], $tenantId );
        }

        if ( function_exists( 'tenant' ) && tenant() ) {
            return self::tenantCallbackUrl( $callbackPath );
        }

        return self::centralCallbackUrl( $callbackPath );
    }

    /**
     * Browser return for recharge / subscription / renew.
     *
     * Stay under EPS BaseUrl (*.affsell.com). /api/* on that host is SPA 404,
     * but /dashboard is a real SPA page — send the user there, then credit when
     * dashboard APIs load (completePendingForTenant) + scheduler backup.
     */
    public static function publicCompleteUrl( string $callback, ?string $tenantId = null ): string
    {
        $epsHost = self::epsRegisteredHost();
        $query   = [ 'eps_callback' => $callback ];

        if ( in_array( $callback, [ 'fail', 'cancel' ], true ) ) {
            $query['message'] = $callback === 'cancel' ? 'Payment cancelled' : 'Payment failed';
        } else {
            $query['message'] = match ( $callback ) {
                'recharge'     => 'Recharge successful',
                'subscription' => 'Subscription added successful',
                'renew'        => 'Renew successful',
                default        => 'Payment successful',
            };
        }

        if ( $tenantId ) {
            return 'https://' . strtolower( $tenantId ) . '.' . $epsHost
                . '/dashboard?' . http_build_query( $query );
        }

        return 'https://' . $epsHost . '/dashboard?' . http_build_query( $query );
    }

    /**
     * Central (non-tenant) callbacks — always on EPS BaseUrl host.
     */
    public static function centralCallbackUrl( string $path ): string
    {
        $mapped = [
            'recharge-success'     => 'recharge',
            'subscription-success' => 'subscription',
            'renew-success'        => 'renew',
        ];

        if ( isset( $mapped[$path] ) ) {
            return self::publicCompleteUrl( $mapped[$path] );
        }

        return 'https://' . self::epsRegisteredHost()
            . '/api/user/aaparpay/' . ltrim( $path, '/' );
    }

    /**
     * Tenant callbacks — EPS BaseUrl host + /api/eps/{tenant}/aaparpay/...
     * (works when SPA proxies /api/* to Laravel; otherwise cron credits PaymentStore flows).
     */
    public static function tenantCallbackUrl( string $path ): string
    {
        $tenantId = function_exists( 'tenant' ) && tenant() ? (string) tenant( 'id' ) : null;

        $mapped = [
            'recharge-success-for-us' => 'recharge',
            'recharge-success'        => 'recharge',
            'subscription-success'    => 'subscription',
            'renew-success'           => 'renew',
        ];

        if ( isset( $mapped[$path] ) ) {
            return self::publicCompleteUrl( $mapped[$path], $tenantId );
        }

        if ( ! $tenantId ) {
            return self::centralCallbackUrl( $path );
        }

        return 'https://' . self::epsRegisteredHost()
            . '/api/eps/' . rawurlencode( $tenantId )
            . '/aaparpay/' . ltrim( $path, '/' );
    }

    private static function callbackUrlsFromSuccess( string $successUrl ): array
    {
        $tenantId = null;
        if ( preg_match( '#https?://([^.]+)\.[^/]+/dashboard#i', $successUrl, $m ) ) {
            $candidate = strtolower( $m[1] );
            if ( ! in_array( $candidate, [ 'www', 'api', 'app', 'admin' ], true ) ) {
                $tenantId = $candidate;
            }
        }
        if ( preg_match( '#[?&]tenant=([^&]+)#', $successUrl, $m ) ) {
            $tenantId = urldecode( $m[1] );
        } elseif ( preg_match( '#/api/eps/([^/]+)/aaparpay/#', $successUrl, $m ) ) {
            $tenantId = urldecode( $m[1] );
        }

        if ( str_contains( $successUrl, '/dashboard' ) || str_contains( $successUrl, 'eps_callback=' ) ) {
            return [
                self::publicCompleteUrl( 'fail', $tenantId ),
                self::publicCompleteUrl( 'cancel', $tenantId ),
            ];
        }

        if ( str_contains( $successUrl, '/api/public/eps/complete' ) ) {
            return [
                self::publicCompleteUrl( 'fail', $tenantId ),
                self::publicCompleteUrl( 'cancel', $tenantId ),
            ];
        }

        if ( str_contains( $successUrl, '/api/user/aaparpay/' ) ) {
            return [
                self::centralCallbackUrl( 'fail' ),
                self::centralCallbackUrl( 'cancel' ),
            ];
        }

        if ( preg_match( '#(/api/eps/[^/]+/aaparpay)/#', $successUrl, $m ) ) {
            $base = 'https://' . self::epsRegisteredHost() . $m[1];

            return [ $base . '/fail', $base . '/cancel' ];
        }

        $base = preg_replace( '#/[^/]+$#', '', $successUrl );

        return [ $base . '/fail', $base . '/cancel' ];
    }

    /**
     * Force callback host under EPS BaseUrl (affsell.com). Prevents Domain mismatch.
     */
    private static function ensureEpsCompatibleCallbackUrl( string $url ): string
    {
        $epsHost = self::epsRegisteredHost();
        $parts   = parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return $url;
        }

        if ( self::hostAllowedByEps( (string) $parts['host'], $epsHost ) ) {
            return $url;
        }

        $queryBag = [];
        parse_str( $parts['query'] ?? '', $queryBag );
        $tenantId = $queryBag['tenant'] ?? null;

        $hostParts = explode( '.', strtolower( (string) $parts['host'] ) );
        if ( ! $tenantId && count( $hostParts ) >= 2 ) {
            $maybe = $hostParts[0];
            if ( ! in_array( $maybe, [ 'www', 'api', 'app', 'admin', 'mail' ], true ) ) {
                $tenantId = $maybe;
            }
        }

        $path = $parts['path'] ?? '/dashboard';
        $qs   = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
        $host = $tenantId ? strtolower( (string) $tenantId ) . '.' . $epsHost : $epsHost;

        Log::warning( 'EPS callback host rewritten to BaseUrl host.', [
            'from' => $parts['host'],
            'to'   => $host,
            'url'  => $url,
        ] );

        return 'https://' . $host . $path . $qs;
    }

    private static function epsRegisteredHost(): string
    {
        $base = trim( (string) config( 'services.eps.base_url', 'https://affsell.com' ) );
        if ( $base !== '' && ! preg_match( '#^https?://#i', $base ) ) {
            $base = 'https://' . ltrim( $base, '/' );
        }

        return strtolower( (string) ( parse_url( $base, PHP_URL_HOST ) ?: 'affsell.com' ) );
    }

    private static function hostAllowedByEps( string $host, string $epsBaseHost ): bool
    {
        $host        = strtolower( $host );
        $epsBaseHost = strtolower( $epsBaseHost );

        return $host === $epsBaseHost || str_ends_with( $host, '.' . $epsBaseHost );
    }

    /**
     * Real Laravel API origin (mdperves.info) — used by recovery / docs, not browser EPS return.
     */
    private static function epsLaravelPublicBase(): string
    {
        $api = trim( (string) ( config( 'services.eps.api_url' ) ?: config( 'app.url' ) ?: '' ) );
        $api = rtrim( $api, '/' );
        $api = preg_replace( '#/api$#i', '', $api ) ?: $api;

        if ( $api !== '' && ! preg_match( '#^https?://#i', $api ) ) {
            $api = 'https://' . ltrim( $api, '/' );
        }

        return $api !== '' ? $api : 'https://mdperves.info';
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
        $transactionId = preg_replace( '/[^A-Za-z0-9\-]/', '', trim( $transactionId ) ) ?: '';

        // EPS requires merchantTransactionId length >= 10
        if ( strlen( $transactionId ) < 10 ) {
            $transactionId = str_pad( $transactionId, 10, '0', STR_PAD_LEFT );
        }

        return substr( $transactionId, 0, 50 );
    }

    private static function sanitizeBdPhone( string $phone ): string
    {
        $digits = preg_replace( '/\D+/', '', $phone ) ?: '';

        if ( str_starts_with( $digits, '880' ) && strlen( $digits ) >= 13 ) {
            $digits = substr( $digits, 2 );
        }

        if ( strlen( $digits ) === 10 && str_starts_with( $digits, '1' ) ) {
            $digits = '0' . $digits;
        }

        if ( ! preg_match( '/^01\d{9}$/', $digits ) ) {
            return '01700000000';
        }

        return $digits;
    }

    private static function sanitizeEmail( string $email ): string
    {
        $email = trim( $email );

        return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : 'customer@example.com';
    }

    private static function sanitizeText( string $value, int $max = 100, bool $allowEmpty = false ): string
    {
        $value = trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );

        if ( $value === '' ) {
            return $allowEmpty ? '' : 'N/A';
        }

        return mb_substr( $value, 0, $max );
    }

    private static function clientIpAddress(): string
    {
        $ip = request()->ip() ?: '127.0.0.1';

        // Live EPS validation often rejects IPv6 / invalid IP formats.
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            return '127.0.0.1';
        }

        return $ip;
    }

    private static function formatEpsError( mixed $data, int $status, ?string $rawBody, string $fallback ): string
    {
        $message = null;

        if ( is_array( $data ) ) {
            // Prefer ASP.NET ProblemDetails.errors over generic title.
            if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
                $parts = [];
                foreach ( $data['errors'] as $field => $messages ) {
                    $text = is_array( $messages ) ? implode( ', ', $messages ) : (string) $messages;
                    $parts[] = is_string( $field ) ? "{$field}: {$text}" : $text;
                }
                $message = implode( ' | ', array_filter( $parts ) );
            }

            if ( blank( $message ) ) {
                $message = $data['ErrorMessage']
                    ?? $data['errorMessage']
                    ?? $data['Message']
                    ?? $data['message']
                    ?? null;
            }

            if ( blank( $message ) && ! empty( $data['ErrorCode'] ) ) {
                $message = 'EPS error code: ' . $data['ErrorCode'];
            }

            if ( blank( $message ) && ! empty( $data['title'] ) ) {
                $message = (string) $data['title'];
            }
        }

        if ( blank( $message ) && filled( $rawBody ) ) {
            $trimmed = trim( $rawBody );
            $message = strlen( $trimmed ) > 400 ? ( substr( $trimmed, 0, 400 ) . '...' ) : $trimmed;
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
        // Official/community SDKs use sandbox-pgapi (hyphen) for sandbox.
        $base = config( 'services.eps.sandbox' )
            ? 'https://sandbox-pgapi.eps.com.bd/v1'
            : 'https://pgapi.eps.com.bd/v1';

        return match ( $name ) {
            'token'      => $base . '/Auth/GetToken',
            'initialize' => $base . '/EPSEngine/InitializeEPS',
            'verify'     => $base . '/EPSEngine/CheckMerchantTransactionStatus',
            default      => throw new RuntimeException( 'Unknown EPS endpoint.' ),
        };
    }
}
