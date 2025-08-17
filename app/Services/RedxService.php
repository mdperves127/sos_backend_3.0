<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Class RedxService.
 */
class RedxService {

    /**
     * @return string
     */
    public static function baseurl() {

        if ( env( 'REDX_MODE' ) == 'sandbox' ) {
            return 'https://sandbox.redx.com.bd/v1.0.0-beta';
        } elseif ( env( 'REDX_MODE' ) == 'live' || env( 'REDX_MODE' ) == 'production' ) {
            return 'https://openapi.redx.com.bd/v1.0.0-beta';
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

        // try {

        //     if ( Session::has( 'pathao_access_token' ) ) {
        //         // dd( Session::get( 'pathao_access_token' ) );
        //         return Session::get( 'pathao_access_token' );
        //     } else {
        //         $response = Http::withHeaders( [
        //             'Accept'       => 'application/json',
        //             'Content-Type' => 'application/json',
        //         ] )->post( self::baseurl() . "/" . "aladdin/api/v1/issue-token", [
        //             'client_id'     => $clientId,
        //             'client_secret' => $clientSecret,
        //             'username'      => $clientEmail,
        //             'password'      => $clientPassword,
        //             'grant_type'    => 'password',
        //         ] );

        //         if ( $response->successful() ) {
        //             $accesstoken = json_decode( $response->body() )->access_token;
        //             Session::put( 'pathao_access_token', $accesstoken );
        //             return $accesstoken;
        //         }

        //         return response()->json( [
        //             'error'    => 'Unable to retrieve token',
        //             'status'   => $response->status(),
        //             'response' => $response->body(),
        //         ], $response->status() );
        //     }

        // } catch ( \Exception $e ) {
        //     return response()->json( ['error' => $e->getMessage()], 500 );
        // }

    }

    public static function getArea( $access_token ) {
        // Ensure there's a space between Bearer and the token
        $response = Http::withHeaders( [
            'API-ACCESS-TOKEN' => "Bearer " . $access_token, // Note the space after Bearer
            'Content-Type'     => 'application/json',
            'Accept'           => 'application/json',
        ] )->get( self::baseurl() . "/areas" );

        return $response;
    }

    public static function newOrderRedx( $access_token, $newOrder ) {

        try {
            $response = Http::withHeaders( [
                'API-ACCESS-TOKEN' => "Bearer " . $access_token,
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
            ] )->post( self::baseurl() . "/parcel", [

                "merchant_invoice_id"    => $newOrder['merchant_order_id'],
                "customer_name"          => $newOrder['recipient_name'],
                "customer_phone"         => $newOrder['recipient_phone'],
                "delivery_area"          => $newOrder['area_name'],
                "delivery_area_id"       => $newOrder['recipient_area'],
                "customer_address"       => $newOrder['recipient_address'],
                "cash_collection_amount" => $newOrder['amount_to_collect'],
                "parcel_weight"          => $newOrder['item_weight'],
                "instruction"            => $newOrder['special_instruction'],
                "type"                   => "product",
                "value"                  => $newOrder['amount_to_collect'],
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
