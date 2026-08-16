<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pathao Courier (Hermes) API client.
 *
 * Aligned with https://github.com/codeboxrcodehub/pathao-courier endpoints:
 * - issue-token, city/zone/area lists, stores, orders, order details, price-plan
 */
class PathaoService
{
    public static function baseUrl(): string
    {
        $sandbox = filter_var(
            config( 'services.pathao.sandbox', env( 'PATHAO_SANDBOX', env( 'PATHAO_MODE', 'live' ) === 'sandbox' ) ),
            FILTER_VALIDATE_BOOLEAN
        );

        return $sandbox
            ? 'https://courier-api-sandbox.pathao.com'
            : 'https://api-hermes.pathao.com';
    }

    /**
     * @return string|array token string on success, error array on failure
     */
    public static function getToken( $clientId, $clientSecret, $clientEmail, $clientPassword, $forceRefresh = false )
    {
        try {
            $cacheKey = 'pathao_access_token_' . md5( (string) $clientId . '|' . (string) $clientEmail . '|' . (string) $clientSecret );

            if ( $forceRefresh ) {
                Cache::forget( $cacheKey );
            }

            $cached = Cache::get( $cacheKey );
            if ( is_string( $cached ) && $cached !== '' ) {
                return $cached;
            }

            $response = Http::withHeaders( [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ] )->timeout( 60 )->post( self::baseUrl() . '/aladdin/api/v1/issue-token', [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'username'      => $clientEmail,
                'password'      => $clientPassword,
                'grant_type'    => 'password',
            ] );

            $json = $response->json();

            if ( $response->successful() && is_array( $json ) && ! empty( $json['access_token'] ) ) {
                $token     = (string) $json['access_token'];
                $expiresIn = max( 60, (int) ( $json['expires_in'] ?? 3600 ) - 60 );
                Cache::put( $cacheKey, $token, $expiresIn );

                return $token;
            }

            return [
                'message' => self::extractMessage( $json, 'Unable to retrieve Pathao token' ),
                'status'  => $response->status(),
                'details' => $json ?: $response->body(),
            ];
        } catch ( \Throwable $e ) {
            return [
                'message' => $e->getMessage(),
                'status'  => 500,
            ];
        }
    }

    public static function cities( $accessToken )
    {
        $response = self::get( $accessToken, 'aladdin/api/v1/countries/1/city-list' );
        if ( self::isError( $response ) ) {
            return $response;
        }

        return self::extractList( $response );
    }

    public static function getZone( $accessToken, $cityId )
    {
        $response = self::get( $accessToken, 'aladdin/api/v1/cities/' . rawurlencode( (string) $cityId ) . '/zone-list' );
        if ( self::isError( $response ) ) {
            return $response;
        }

        return self::extractList( $response );
    }

    public static function getArea( $accessToken, $zoneId )
    {
        $response = self::get( $accessToken, 'aladdin/api/v1/zones/' . rawurlencode( (string) $zoneId ) . '/area-list' );
        if ( self::isError( $response ) ) {
            return $response;
        }

        return self::extractList( $response );
    }

    /** GET aladdin/api/v1/stores?page= */
    public static function stores( $accessToken, int $page = 1 )
    {
        $response = self::get( $accessToken, 'aladdin/api/v1/stores?page=' . max( 1, $page ) );
        if ( self::isError( $response ) ) {
            return $response;
        }

        return $response['data'] ?? $response;
    }

    /** POST aladdin/api/v1/stores */
    public static function createStore( $accessToken, array $storeInfo )
    {
        $required = ['name', 'contact_name', 'contact_number', 'address', 'city_id', 'zone_id', 'area_id'];
        foreach ( $required as $field ) {
            if ( ! isset( $storeInfo[$field] ) || $storeInfo[$field] === '' || $storeInfo[$field] === null ) {
                return [
                    'message' => "{$field} is required",
                    'status'  => 422,
                ];
            }
        }

        return self::post( $accessToken, 'aladdin/api/v1/stores', $storeInfo );
    }

    public static function newOrder( $accessToken, $storeId, $newOrder )
    {
        try {
            $payload = [
                'store_id'            => (int) $storeId,
                'merchant_order_id'   => (string) self::value( $newOrder, 'merchant_order_id', '' ),
                'recipient_name'      => (string) self::value( $newOrder, 'recipient_name', '' ),
                'recipient_phone'     => (string) self::value( $newOrder, 'recipient_phone', '' ),
                'recipient_address'   => (string) self::value( $newOrder, 'recipient_address', '' ),
                'delivery_type'       => (int) self::value( $newOrder, 'delivery_type', 48 ),
                'item_type'           => (int) self::value( $newOrder, 'item_type', 2 ),
                'special_instruction' => (string) self::value( $newOrder, 'special_instruction', '' ),
                'item_quantity'       => (int) self::value( $newOrder, 'item_quantity', 1 ),
                'item_weight'         => (string) self::value( $newOrder, 'item_weight', '0.5' ),
                'amount_to_collect'   => (int) self::value( $newOrder, 'amount_to_collect', 0 ),
                'item_description'    => (string) self::value( $newOrder, 'item_description', '' ),
            ];

            foreach ( ['recipient_city', 'recipient_zone', 'recipient_area'] as $field ) {
                $val = self::value( $newOrder, $field, null );
                if ( $val !== null && $val !== '' ) {
                    $payload[$field] = (int) $val;
                }
            }

            $response = self::post( $accessToken, 'aladdin/api/v1/orders', $payload );
            if ( self::isError( $response ) ) {
                return $response;
            }

            return $response;
        } catch ( \Throwable $e ) {
            return [
                'message' => $e->getMessage(),
                'status'  => 500,
            ];
        }
    }

    /** GET aladdin/api/v1/orders/{consignmentId} */
    public static function orderDetails( $accessToken, $consignmentId )
    {
        return self::get( $accessToken, 'aladdin/api/v1/orders/' . rawurlencode( (string) $consignmentId ) );
    }

    /** POST aladdin/api/v1/merchant/price-plan */
    public static function priceCalculation( $accessToken, array $payload )
    {
        $required = ['store_id', 'item_type', 'delivery_type', 'item_weight', 'recipient_city', 'recipient_zone'];
        foreach ( $required as $field ) {
            if ( ! isset( $payload[$field] ) || $payload[$field] === '' || $payload[$field] === null ) {
                return [
                    'message' => "{$field} is required",
                    'status'  => 422,
                ];
            }
        }

        return self::post( $accessToken, 'aladdin/api/v1/merchant/price-plan', $payload );
    }

    /** POST aladdin/api/v1/orders/{id}/cancel */
    public static function cancelOrder( $accessToken, $consignmentId )
    {
        return self::post( $accessToken, 'aladdin/api/v1/orders/' . rawurlencode( (string) $consignmentId ) . '/cancel' );
    }

    public static function isCreateSuccess( array $response ): bool
    {
        return isset( $response['data']['consignment_id'] );
    }

    public static function isError( $response ): bool
    {
        return is_array( $response )
            && isset( $response['message'] )
            && ( isset( $response['status'] ) || isset( $response['details'] ) )
            && ! isset( $response['data'] );
    }

    private static function get( $accessToken, string $path ): array
    {
        return self::request( $accessToken, 'get', $path );
    }

    private static function post( $accessToken, string $path, array $body = [] ): array
    {
        return self::request( $accessToken, 'post', $path, $body );
    }

    private static function request( $accessToken, string $method, string $path, array $body = [] ): array
    {
        try {
            if ( ! is_string( $accessToken ) || $accessToken === '' ) {
                return [
                    'message' => 'Invalid Pathao access token.',
                    'status'  => 401,
                ];
            }

            $token = str_starts_with( $accessToken, 'Bearer ' ) ? $accessToken : ( 'Bearer ' . $accessToken );

            $http = Http::withHeaders( [
                'Authorization' => $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ] )->timeout( 60 );

            $url = rtrim( self::baseUrl(), '/' ) . '/' . ltrim( $path, '/' );

            $response = strtolower( $method ) === 'get'
                ? $http->get( $url )
                : $http->post( $url, $body );

            $json = $response->json();
            if ( ! is_array( $json ) ) {
                $json = ['raw' => $response->body()];
            }

            if ( $response->failed() ) {
                Log::warning( 'Pathao API request failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => $json,
                ] );

                return [
                    'message' => self::extractMessage( $json, 'Pathao API request failed' ),
                    'status'  => $response->status(),
                    'details' => $json,
                ];
            }

            return $json;
        } catch ( \Throwable $e ) {
            Log::error( 'Pathao API exception', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ] );

            return [
                'message' => $e->getMessage(),
                'status'  => 500,
            ];
        }
    }

    private static function extractList( array $response )
    {
        if ( isset( $response['data']['data'] ) && is_array( $response['data']['data'] ) ) {
            return $response['data']['data'];
        }

        if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            return $response['data'];
        }

        return $response;
    }

    private static function extractMessage( $json, string $fallback ): string
    {
        if ( is_array( $json ) ) {
            if ( isset( $json['message'] ) && is_string( $json['message'] ) && $json['message'] !== '' ) {
                return $json['message'];
            }
            if ( isset( $json['errors'] ) ) {
                $encoded = json_encode( $json['errors'] );
                if ( is_string( $encoded ) && $encoded !== '[]' && $encoded !== '{}' ) {
                    return $encoded;
                }
            }
        }

        return $fallback;
    }

    private static function value( $source, string $key, $default = null )
    {
        if ( is_array( $source ) ) {
            return $source[$key] ?? $default;
        }
        if ( is_object( $source ) ) {
            return $source->{$key} ?? $default;
        }

        return $default;
    }
}
