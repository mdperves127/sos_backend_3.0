<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SmsService
{
    public static function sendSms( mixed $data ): void
    {
        $number     = self::extractNumber( $data );
        $verifyCode = self::extractVerifyCode( $data );

        if ( ! $number || ! $verifyCode ) {
            throw new RuntimeException( 'SMS recipient number and verification code are required.' );
        }

        $apiUrl   = config( 'services.mimsms.url' );
        $username = config( 'services.mimsms.username' );
        $apiKey   = config( 'services.mimsms.api_key' );
        $sender   = config( 'services.mimsms.sender_name' );

        if ( ! $username || ! $apiKey || ! $sender ) {
            throw new RuntimeException( 'SMS gateway is not configured.' );
        }

        $payload = [
            'UserName'        => $username,
            'Apikey'          => $apiKey,
            'MobileNumber'    => self::normalizePhoneNumber( $number ),
            'CampaignId'      => 'null',
            'SenderName'      => $sender,
            'TransactionType' => config( 'services.mimsms.transaction_type', 'T' ),
            'Message'         => 'Your OTP Code Is : ' . $verifyCode,
        ];

        try {
            $client   = new Client( ['timeout' => 15] );
            $response = $client->post( $apiUrl, [
                'json'    => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
            ] );

            $body    = $response->getBody()->getContents();
            $decoded = json_decode( $body, true );

            if ( ! is_array( $decoded ) ) {
                Log::error( 'SMS API returned invalid JSON.', ['body' => $body] );
                throw new RuntimeException( 'SMS gateway returned an invalid response.' );
            }

            $providerStatus = (string) ( $decoded['statusCode'] ?? $response->getStatusCode() );

            if ( $providerStatus !== '200' ) {
                $reason = $decoded['responseResult'] ?? $decoded['status'] ?? 'SMS sending failed.';
                Log::error( 'SMS API rejected request.', [
                    'mobile'   => $payload['MobileNumber'],
                    'status'   => $providerStatus,
                    'reason'   => $reason,
                    'response' => $decoded,
                ] );
                throw new RuntimeException( (string) $reason );
            }
        } catch ( GuzzleException $e ) {
            Log::error( 'SMS API transport error: ' . $e->getMessage() );
            throw new RuntimeException( 'Unable to reach SMS gateway. Please try again later.' );
        }
    }

    public static function normalizePhoneNumber( string $number ): string
    {
        $number = preg_replace( '/\D+/', '', $number ) ?? '';

        if ( str_starts_with( $number, '880' ) ) {
            return $number;
        }

        if ( str_starts_with( $number, '0' ) ) {
            return '88' . $number;
        }

        return '880' . $number;
    }

    private static function extractNumber( mixed $data ): ?string
    {
        if ( is_object( $data ) ) {
            return $data->number ?? $data->phone ?? null;
        }

        return $data['number'] ?? $data['phone'] ?? null;
    }

    private static function extractVerifyCode( mixed $data ): ?string
    {
        if ( is_object( $data ) ) {
            return isset( $data->verify_code ) ? (string) $data->verify_code : null;
        }

        return isset( $data['verify_code'] ) ? (string) $data['verify_code'] : null;
    }
}
