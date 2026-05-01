<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

/**
 * Class PathaoService.
 */
class PathaoService {

    /**
     * @return string
     */
    public static function baseurl() {
        $mode = env( 'PATHAO_MODE', 'live' );

        if ( $mode == 'sandbox' ) {
            return 'https://courier-api-sandbox.pathao.com';
        }

        return 'https://api-hermes.pathao.com';
    }

    /**
     * @param $clientId
     * @param $clientSecret
     * @param $clientEmail
     * @param $clientPassword
     *
     * @return mixed
     */
    public static function getToken( $clientId, $clientSecret, $clientEmail, $clientPassword, $forceRefresh = false ) {
        try {
            $sessionKey = 'pathao_access_token_' . md5( $clientId . '|' . $clientEmail . '|' . $clientSecret );

            if ( $forceRefresh ) {
                Session::forget( $sessionKey );
            }

            if ( Session::has( $sessionKey ) ) {
                return Session::get( $sessionKey );
            } else {
                $response = Http::withHeaders( [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ] )->post( self::baseurl() . "/" . "aladdin/api/v1/issue-token", [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'username'      => $clientEmail,
                    'password'      => $clientPassword,
                    'grant_type'    => 'password',
                ] );

                if ( $response->successful() && isset( $response->json()['access_token'] ) ) {
                    $accesstoken = $response->json()['access_token'];
                    Session::put( $sessionKey, $accesstoken );
                    return $accesstoken;
                }

                return [
                    'message' => 'Unable to retrieve token',
                    'status'  => $response->status(),
                    'details' => $response->json() ?: $response->body(),
                ];
            }

        } catch ( \Exception $e ) {
            return [
                'message' => $e->getMessage(),
                'status'  => 500,
            ];
        }

    }

    public static function cities( $access_token ) {
        $response = Http::withHeaders( [
            'Authorization' => $access_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ] )->get( self::baseurl() . "/" . "/aladdin/api/v1/countries/1/city-list" );

        return $response['data']['data'];
    }

    public static function getZone( $access_token, $cityId ) {
        $response = Http::withHeaders( [
            'Authorization' => $access_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ] )->get( self::baseurl() . "/" . "/aladdin/api/v1/cities/$cityId/zone-list" );

        return $response['data']['data'];
    }

    public static function getArea( $access_token, $zoneId ) {
        $response = Http::withHeaders( [
            'Authorization' => $access_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ] )->get( self::baseurl() . "/" . "/aladdin/api/v1/zones/$zoneId/area-list" );

        return $response['data']['data'];
    }

    public static function newOrder( $access_token, $store_id, $newOrder ) {

        try {
            $payload = [
                'store_id'            => (int) $store_id,
                'merchant_order_id'   => (string) ( $newOrder['merchant_order_id'] ?? '' ),
                'recipient_name'      => (string) ( $newOrder['recipient_name'] ?? '' ),
                'recipient_phone'     => (string) ( $newOrder['recipient_phone'] ?? '' ),
                'recipient_address'   => (string) ( $newOrder['recipient_address'] ?? '' ),
                'delivery_type'       => (int) ( $newOrder['delivery_type'] ?? 48 ),
                'item_type'           => (int) ( $newOrder['item_type'] ?? 2 ),
                'special_instruction' => (string) ( $newOrder['special_instruction'] ?? '' ),
                'item_quantity'       => (int) ( $newOrder['item_quantity'] ?? 1 ),
                'item_weight'         => (string) ( $newOrder['item_weight'] ?? '1' ),
                'amount_to_collect'   => (int) ( $newOrder['amount_to_collect'] ?? 0 ),
                'item_description'    => (string) ( $newOrder['item_description'] ?? '' ),
            ];

            // Optional fields: include only when present (matching successful manual payload style)
            if ( isset( $newOrder['recipient_city'] ) && $newOrder['recipient_city'] !== null && $newOrder['recipient_city'] !== '' ) {
                $payload['recipient_city'] = (int) $newOrder['recipient_city'];
            }
            if ( isset( $newOrder['recipient_zone'] ) && $newOrder['recipient_zone'] !== null && $newOrder['recipient_zone'] !== '' ) {
                $payload['recipient_zone'] = (int) $newOrder['recipient_zone'];
            }
            if ( isset( $newOrder['recipient_area'] ) && $newOrder['recipient_area'] !== null && $newOrder['recipient_area'] !== '' ) {
                $payload['recipient_area'] = (int) $newOrder['recipient_area'];
            }


            $response = Http::withHeaders( [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                ] )->post( self::baseurl() . "/aladdin/api/v1/orders", $payload );
            if ( $response->failed() ) {
                return [
                    'message' => 'Pathao API request failed',
                    'status'  => $response->status(),
                    'details' => $response->json() ?: $response->body(),
                ];
            }

            return $response->json();
        } catch ( \Exception $e ) {
            return [
                'message' => $e->getMessage(),
                'status'  => 500,
            ];
        }
    }

}
