<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

/**
 * Class PathaoService.
 */
class SteadFastService {

    /**
     * @return string
     */
    public static function baseurl() {
        return 'https://portal.packzy.com/api/v1';
    }

    /**
     * @param $API_KEY
     * @param $SECRET_KEY
     * @param $ORDER_DELIVERY_TO_COURIER
     * @return mixed
     */

    public static function order( $api_key, $secret_key, $newOrder ) {

        try {
            $response = Http::withHeaders( [
                'Api-Key' => $api_key,
                'Secret-Key' => $secret_key,
                'Content-Type'  => 'application/json',
            ] )->post( self::baseurl() . "/create_order", [

                "invoice"   => $newOrder['merchant_order_id'],
                "recipient_name"      => $newOrder['recipient_name'],
                "recipient_phone"     => $newOrder['recipient_phone'],
                "recipient_address"   => $newOrder['recipient_address'],
                "cod_amount"   => $newOrder['amount_to_collect'],
                "note"   => $newOrder['special_instruction'],
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
