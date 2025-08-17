<?php

namespace App\Services;
use Illuminate\Support\Facades\Auth;

/**
 * Class AamarPayService.
 */
class AamarPayService {
    static function gateway( $price, $traxId, $type, $successUrl ) {
        $success = $successUrl;
        $cancel  = url( 'api/aaparpay/cancel' );
        $fail    = url( 'api/aaparpay/fail' );

        $curl = curl_init();

        curl_setopt_array( $curl, [
            CURLOPT_URL            => config( 'app.aamarpay' ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => $price,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => [
                'store_id'      => env( 'APP_ENV' ) === 'production' ? 'startownstartup' : 'aamarpaytest',
                'signature_key' => env( 'APP_ENV' ) === 'production' ? '8967d60ebdd9fe3f1e6a419fb65ee2e7' : 'dbb74894e82415a2f7ff0ec3a97e4183',
                'cus_name'      => Auth::user()->name,
                'cus_email'     => 'example@gmail.com',
                'cus_phone'     => '01870******',
                'amount'        => $price,
                'currency'      => 'BDT',
                'tran_id'       => $traxId,
                'desc'          => 'desc',
                'success_url'   => $success,
                'fail_url'      => $fail,
                'cancel_url'    => $cancel,
                'type'          => 'json',
                'opt_a'         => $type,
            ],
        ] );

        $response = curl_exec( $curl );

        curl_close( $curl );
        return json_decode( $response );
    }
}
