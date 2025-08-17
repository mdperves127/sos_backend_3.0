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

        if ( env( 'PATHAO_MODE' ) == 'sandbox' ) {
            return 'https://courier-api-sandbox.pathao.com';
        } elseif ( env( 'PATHAO_MODE' ) == 'live' || env( 'PATHAO_MODE' ) == 'production' ) {
            return 'https://api-hermes.pathao.com';
        }
    }

    /**
     * @param $clientId
     * @param $clientSecret
     * @param $clientEmail
     * @param $clientPassword
     *
     * @return mixed
     */
    public static function getToken( $clientId, $clientSecret, $clientEmail, $clientPassword ) {

        // dd( $clientId, $clientSecret, $clientEmail, $clientPassword );

        try {

            if ( Session::has( 'pathao_access_token' ) ) {
                // dd( Session::get( 'pathao_access_token' ) );
                return Session::get( 'pathao_access_token' );
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

                if ( $response->successful() ) {
                    $accesstoken = json_decode( $response->body() )->access_token;
                    Session::put( 'pathao_access_token', $accesstoken );
                    return $accesstoken;
                }

                return response()->json( [
                    'error'    => 'Unable to retrieve token',
                    'status'   => $response->status(),
                    'response' => $response->body(),
                ], $response->status() );
            }

        } catch ( \Exception $e ) {
            return response()->json( ['error' => $e->getMessage()], 500 );
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
            $response = Http::withHeaders( [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ] )->post( self::baseurl() . "/aladdin/api/v1/orders", [

                "store_id"            => $store_id,
                "merchant_order_id"   => $newOrder['merchant_order_id'],
                "recipient_name"      => $newOrder['recipient_name'],
                "recipient_phone"     => $newOrder['recipient_phone'],
                "recipient_address"   => $newOrder['recipient_address'],
                "recipient_city"      => $newOrder['recipient_city'],
                "recipient_zone"      => $newOrder['recipient_zone'],
                "recipient_area"      => $newOrder['recipient_area'],
                "delivery_type"       => $newOrder['delivery_type'],
                "item_type"           => $newOrder['item_type'],
                "special_instruction" => $newOrder['special_instruction'],
                "item_quantity"       => $newOrder['item_quantity'],
                "item_weight"         => $newOrder['item_weight'],
                "amount_to_collect"   => $newOrder['amount_to_collect'],
                "item_description"    => $newOrder['item_description'],
            ] );

            // Check for errors
            if ( $response->failed() ) {
                return response()->json( [
                    'error'    => 'API request failed',
                    'response' => $response->body(),
                    'status'   => $response->status(),
                ], $response->status() );
            }

            return $response->json();
        } catch ( \Exception $e ) {
            return response()->json( $e );
        }
    }

}
