<?php

namespace App\Services;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * Class SmsService.
 */
class SmsService
{
    static function sendSms($data)
    {
        // API endpoint and credentials
        $apiUrl = 'https://api.mimsms.com/api/SmsSending/SMS';
        $username = 'shikhboami@gmail.com';
        $apiKey = '1E7HYEO8WI7NRJ9';
        $senderId = '8809601002601';
        $receiver_number = (string) '88'. $data['number'];
        $text = (string) $data['verify_code'];

        // Prepare payload
        $payload = [
            'UserName' => $username,
            'Apikey' => $apiKey,
            'MobileNumber' => $receiver_number,
            'CampaignId' => 'null',
            'SenderName' => $senderId,
            'SenderId' => 'test sender',
            'TransactionType' => 'T',
            'Message' => 'Your OTP Code Is : ' . $text,
        ];

        // Send request using GuzzleHTTP
        try {
            $client = new Client();
            $response = $client->post($apiUrl, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]
            ]);

            // Handle response
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            // Return response to client
            return response()->json([
                'status_code' => $statusCode,
                'response_body' => $body
            ]);
        } catch (ClientException $e) {
            // Log error
            \Log::error('SMS API Error: ' . $e->getMessage());

            // Get error response from API
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            // Return error response
            return response()->json([
                'status_code' => $statusCode,
                'error' => $body
            ], $statusCode);
        }
    }
}
