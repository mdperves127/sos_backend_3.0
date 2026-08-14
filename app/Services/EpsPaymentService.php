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
        // Browser returns to frontend static page (affsell.com), which calls Laravel API to credit.
        $centralMapped = match ( $callbackPath ) {
            'recharge-success-for-us', 'recharge-success' => 'recharge',
            'subscription-success'                       => 'subscription',
            'renew-success'                              => 'renew',
            default                                      => null,
        };

        if ( $centralMapped !== null ) {
            $tenantId = ( function_exists( 'tenant' ) && tenant() ) ? tenant( 'id' ) : null;

            return self::epsFrontendReturnUrl( $centralMapped, $tenantId ? (string) $tenantId : null );
        }

        if ( function_exists( 'tenant' ) && tenant() ) {
            return self::tenantCallbackUrl( $callbackPath );
        }

        return self::centralCallbackUrl( $callbackPath );
    }

    /**
     * Frontend return page on the EPS-registered domain.
     * Must be a real static file on the SPA host (public/eps-return.html).
     */
    public static function epsFrontendReturnUrl( string $callback, ?string $tenantId = null ): string
    {
        $returnUrl = trim( (string) config( 'services.eps.return_url', 'https://affsell.com/eps-return.html' ) );
        if ( $returnUrl === '' ) {
            $returnUrl = self::epsCallbackBaseUrl() . '/eps-return.html';
        }
        if ( ! preg_match( '#^https?://#i', $returnUrl ) ) {
            $returnUrl = 'https://' . ltrim( $returnUrl, '/' );
        }

        $apiUrl = rtrim( (string) ( config( 'services.eps.api_url' ) ?: config( 'app.url' ) ), '/' );

        $query = array_filter( [
            'callback' => $callback,
            'tenant'   => $tenantId,
            'api'      => $apiUrl,
        ], fn ( $value ) => $value !== null && $value !== '' );

        $separator = str_contains( $returnUrl, '?' ) ? '&' : '?';

        return $returnUrl . $separator . http_build_query( $query );
    }

    /** @deprecated use epsFrontendReturnUrl */
    public static function epsPhysicalCallbackUrl( string $callback, ?string $tenantId = null ): string
    {
        return self::epsFrontendReturnUrl( $callback, $tenantId );
    }

    public static function centralCallbackUrl( string $path ): string
    {
        if ( in_array( $path, ['recharge-success', 'subscription-success', 'renew-success', 'fail', 'cancel'], true ) ) {
            $mapped = match ( $path ) {
                'recharge-success'     => 'recharge',
                'subscription-success' => 'subscription',
                'renew-success'        => 'renew',
                default                => $path,
            };

            return self::epsFrontendReturnUrl( $mapped );
        }

        return self::epsCallbackBaseUrl() . '/api/user/aaparpay/' . ltrim( $path, '/' );
    }

    public static function tenantCallbackUrl( string $path ): string
    {
        $tenantId = function_exists( 'tenant' ) ? tenant( 'id' ) : null;

        $centralMapped = match ( $path ) {
            'recharge-success-for-us', 'recharge-success' => 'recharge',
            'subscription-success'                       => 'subscription',
            'renew-success'                              => 'renew',
            'fail', 'cancel'                             => $path,
            default                                      => null,
        };

        if ( $centralMapped !== null ) {
            return self::epsFrontendReturnUrl( $centralMapped, $tenantId ? (string) $tenantId : null );
        }

        // Product checkout etc. — also use frontend return + public API when possible.
        // Keep path tenancy API as secondary.
        if ( $tenantId ) {
            return self::epsFrontendReturnUrl( $path, (string) $tenantId );
        }

        return self::ensureEpsCompatibleCallbackUrl(
            rtrim( request()->getSchemeAndHttpHost(), '/' ) . '/api/aaparpay/' . ltrim( $path, '/' )
        );
    }

    private static function callbackUrlsFromSuccess( string $successUrl ): array
    {
        $tenantId = null;
        if ( str_contains( $successUrl, 'eps-return.html' ) || str_contains( $successUrl, 'eps-callback.php' ) ) {
            $parts = parse_url( $successUrl );
            parse_str( $parts['query'] ?? '', $query );
            $tenantId = $query['tenant'] ?? null;

            return [
                self::epsFrontendReturnUrl( 'fail', $tenantId ),
                self::epsFrontendReturnUrl( 'cancel', $tenantId ),
            ];
        }

        if ( str_contains( $successUrl, '/api/user/aaparpay/' ) ) {
            return [
                self::centralCallbackUrl( 'fail' ),
                self::centralCallbackUrl( 'cancel' ),
            ];
        }

        if ( preg_match( '#/api/eps/([^/]+)/aaparpay/#', $successUrl, $matches ) ) {
            $base = self::epsCallbackBaseUrl() . '/api/eps/' . $matches[1] . '/aaparpay';

            return [
                $base . '/fail',
                $base . '/cancel',
            ];
        }

        $base = preg_replace( '#/[^/]+$#', '', $successUrl );

        return [
            self::ensureEpsCompatibleCallbackUrl( $base . '/fail' ),
            self::ensureEpsCompatibleCallbackUrl( $base . '/cancel' ),
        ];
    }

    /**
     * Host registered with EPS (e.g. https://affsell.com). Callbacks must use this domain.
     */
    private static function epsCallbackBaseUrl(): string
    {
        $configured = trim( (string) config( 'services.eps.base_url' ) );

        if ( $configured === '' ) {
            $configured = trim( (string) ( config( 'app.maindomain' ) ?: config( 'app.url' ) ) );
        }

        // Allow domain-only values from EPS portal copy/paste (affsell.com)
        if ( $configured !== '' && ! preg_match( '#^https?://#i', $configured ) ) {
            $configured = 'https://' . ltrim( $configured, '/' );
        }

        return rtrim( $configured, '/' );
    }

    /**
     * Rewrite callback host to the EPS-registered BaseUrl; keep tenant path when needed.
     */
    private static function ensureEpsCompatibleCallbackUrl( string $url ): string
    {
        $url = trim( $url );

        if ( $url === '' ) {
            return $url;
        }

        // Tenant context still using old host-based /api/aaparpay paths → convert.
        if (
            function_exists( 'tenant' )
            && tenant( 'id' )
            && preg_match( '#/api/aaparpay/([^?\s]+)#', $url, $matches )
            && ! str_contains( $url, '/api/eps/' )
        ) {
            return self::tenantCallbackUrl( $matches[1] );
        }

        $base      = self::epsCallbackBaseUrl();
        $baseParts = parse_url( $base ) ?: [];
        $parts     = parse_url( $url ) ?: [];

        $scheme = $baseParts['scheme'] ?? 'https';
        $host   = $baseParts['host'] ?? null;

        if ( ! $host ) {
            return $url;
        }

        $path  = $parts['path'] ?? '/';
        $query = isset( $parts['query'] ) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $path . $query;
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
