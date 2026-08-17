<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RedX Courier OpenAPI client.
 *
 * @see https://redx.com.bd/developer-api/
 */
class RedxService {

    public static function baseurl() {
        $sandbox = (bool) config( 'services.redx.sandbox', env( 'REDX_MODE', 'live' ) === 'sandbox' );

        if ( $sandbox ) {
            return 'https://sandbox.redx.com.bd/v1.0.0-beta';
        }

        return 'https://openapi.redx.com.bd/v1.0.0-beta';
    }

    public static function getArea( $access_token ) {
        $response = Http::withHeaders( self::headers( $access_token ) )
            ->timeout( 60 )
            ->get( self::baseurl() . '/areas' );

        return $response->body();
    }

    public static function areas( $accessToken, array $query = [] ): array
    {
        return self::request( $accessToken, 'get', '/areas', [], $query );
    }

    public static function trackParcel( $accessToken, string $trackingId ): array
    {
        return self::request( $accessToken, 'get', '/parcel/track/' . rawurlencode( $trackingId ) );
    }

    public static function parcelInfo( $accessToken, string $trackingId ): array
    {
        return self::request( $accessToken, 'get', '/parcel/info/' . rawurlencode( $trackingId ) );
    }

    public static function createPickupStore( $accessToken, array $payload ): array
    {
        return self::request( $accessToken, 'post', '/pickup/store', $payload );
    }

    public static function pickupStores( $accessToken ): array
    {
        return self::request( $accessToken, 'get', '/pickup/stores' );
    }

    public static function pickupStore( $accessToken, $storeId ): array
    {
        return self::request( $accessToken, 'get', '/pickup/store/info/' . rawurlencode( (string) $storeId ) );
    }

    public static function chargeCalculator( $accessToken, array $query ): array
    {
        return self::request( $accessToken, 'get', '/charge/charge_calculator', [], $query );
    }

    public static function updateParcel( $accessToken, array $payload ): array
    {
        return self::request( $accessToken, 'patch', '/parcels', $payload );
    }

    public static function newOrderRedx( $access_token, $newOrder ) {
        $payload = [
            'merchant_invoice_id'    => (string) self::value( $newOrder, 'merchant_order_id', self::value( $newOrder, 'merchant_invoice_id', '' ) ),
            'customer_name'          => (string) self::value( $newOrder, 'recipient_name', self::value( $newOrder, 'customer_name', '' ) ),
            'customer_phone'         => (string) self::value( $newOrder, 'recipient_phone', self::value( $newOrder, 'customer_phone', '' ) ),
            'delivery_area'          => (string) self::value( $newOrder, 'area_name', self::value( $newOrder, 'delivery_area', '' ) ),
            'delivery_area_id'       => (int) self::value( $newOrder, 'recipient_area', self::value( $newOrder, 'delivery_area_id', 0 ) ),
            'customer_address'       => (string) self::value( $newOrder, 'recipient_address', self::value( $newOrder, 'customer_address', '' ) ),
            'cash_collection_amount' => self::value( $newOrder, 'amount_to_collect', self::value( $newOrder, 'cash_collection_amount', 0 ) ),
            'parcel_weight'          => self::value( $newOrder, 'item_weight', self::value( $newOrder, 'parcel_weight', 0 ) ),
            'instruction'            => (string) self::value( $newOrder, 'special_instruction', self::value( $newOrder, 'instruction', '' ) ),
            'type'                   => (string) self::value( $newOrder, 'type', 'product' ),
            'value'                  => self::value( $newOrder, 'value', self::value( $newOrder, 'amount_to_collect', 0 ) ),
        ];

        $pickupStoreId = self::value( $newOrder, 'pickup_store_id', null );
        if ( $pickupStoreId !== null && $pickupStoreId !== '' ) {
            $payload['pickup_store_id'] = $pickupStoreId;
        }

        return self::request( $access_token, 'post', '/parcel', $payload );
    }

    public static function isError( array $payload ): bool
    {
        return isset( $payload['message'] ) && (int) ( $payload['status'] ?? 0 ) >= 400;
    }

    private static function headers( $accessToken ): array
    {
        return [
            'API-ACCESS-TOKEN' => 'Bearer ' . ltrim( (string) $accessToken, ' ' ),
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
        ];
    }

    private static function request( $accessToken, string $method, string $path, array $body = [], array $query = [] ): array
    {
        try {
            $http = Http::withHeaders( self::headers( $accessToken ) )->timeout( 60 );
            $url  = self::baseurl() . $path;

            $response = match ( strtolower( $method ) ) {
                'get' => $http->get( $url, $query ),
                'patch' => $http->patch( $url, $body ),
                default => $http->post( $url, $body ),
            };

            $json = $response->json();
            if ( ! is_array( $json ) ) {
                $json = ['raw' => $response->body()];
            }

            if ( $response->failed() ) {
                Log::warning( 'RedX API request failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => $json,
                ] );

                return [
                    'message' => self::extractErrorMessage( $json, 'RedX API request failed' ),
                    'status'  => $response->status(),
                    'details' => $json,
                ];
            }

            if ( ! isset( $json['status'] ) ) {
                $json['status'] = $response->status();
            }

            return $json;
        } catch ( \Throwable $e ) {
            Log::error( 'RedX API exception', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ] );

            return [
                'message' => $e->getMessage(),
                'status'  => 500,
            ];
        }
    }

    private static function extractErrorMessage( array $json, string $fallback ): string
    {
        foreach ( ['message', 'error', 'errors'] as $key ) {
            if ( ! isset( $json[$key] ) ) {
                continue;
            }
            if ( is_string( $json[$key] ) && trim( $json[$key] ) !== '' ) {
                return $json[$key];
            }
            if ( is_array( $json[$key] ) ) {
                $encoded = json_encode( $json[$key] );
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
