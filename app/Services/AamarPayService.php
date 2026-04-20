<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Class AamarPayService.
 */
class AamarPayService {
    static function gateway( $price, $traxId, $type, $successUrl, $tenant_type ) {
        $success = $successUrl;

        $cancel  = url( 'api/aaparpay/cancel' );
        $fail    = url( 'api/aaparpay/fail' );
        $user    = Auth::user();

        $curl = curl_init();

        $curlOptions = [
            CURLOPT_URL            => config( 'app.aamarpay' ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => [
                'store_id'      => env( 'APP_ENV' ) === 'production' ? 'aamarpaytest' : 'aamarpaytest',
                'signature_key' => env( 'APP_ENV' ) === 'production' ? 'dbb74894e82415a2f7ff0ec3a97e4183' : 'dbb74894e82415a2f7ff0ec3a97e4183',
                'cus_name'      => $user?->name ?? 'Customer',
                'cus_email'     => $user?->email ?? 'example@gmail.com',
                'cus_phone'     => $user?->number ?? '01870000000',
                'amount'        => $price,
                'currency'      => 'BDT',
                'tran_id'       => $traxId,
                'desc'          => 'desc',
                'success_url'   => $success,
                'fail_url'      => $fail,
                'cancel_url'    => $cancel,
                'type'          => 'json',
                'opt_a'         => $type,
                'opt_b'         => $tenant_type,
            ],
        ];

        // Sandbox currently presents an expired certificate in local development.
        if ( app()->environment( 'local' ) ) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array( $curl, $curlOptions );

        $response = curl_exec( $curl );
        $curlError = curl_error( $curl );
        $httpCode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );

        curl_close( $curl );

        if ( $response === false ) {
            Log::error( 'AamarPay request failed', [
                'type'        => $type,
                'tenant_type' => $tenant_type,
                'transaction' => $traxId,
                'error'       => $curlError,
                'http_code'   => $httpCode,
            ] );

            $errorResponse = [
                'result'  => 'false',
                'message' => 'Unable to connect to AamarPay.',
            ];

            if ( app()->environment( 'local' ) ) {
                $errorResponse['error'] = $curlError;
            }

            return $errorResponse;
        }

        $decodedResponse = json_decode( $response, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            Log::error( 'AamarPay returned invalid JSON', [
                'type'        => $type,
                'tenant_type' => $tenant_type,
                'transaction' => $traxId,
                'http_code'   => $httpCode,
                'response'    => $response,
            ] );

            $errorResponse = [
                'result'  => 'false',
                'message' => 'AamarPay returned an invalid response.',
            ];

            if ( app()->environment( 'local' ) ) {
                $errorResponse['error'] = $response;
            }

            return $errorResponse;
        }

        return $decodedResponse;
    }
}
