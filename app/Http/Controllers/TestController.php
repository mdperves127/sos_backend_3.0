<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class TestController extends Controller
{
    //

    function index(Request $request){
        $request->validate([
            'age'=>'required',
            'name'=> ['present_with:age',]
        ]);

        return 1;

    }

    // public function sendSms(Request $request)
    // {
    //     // API endpoint and credentials
    //     $apiUrl = 'https://api.mimsms.com/api/SmsSending/SMS';
    //     $username = 'shikhboami@gmail.com';
    //     $apiKey = '1E7HYEO8WI7NRJ9';

    //     // Prepare payload
    //     $payload = [
    //         'UserName' => $username,
    //         'Apikey' => $apiKey,
    //         'MobileNumber' => $request->input('mobile_number'),
    //         'CampaignId' => null,
    //         'SenderName' => 'MiM SMS',
    //         'TransactionType' => 'T',
    //         'Message' => 'My first API SMS from MiM Digital'
    //     ];

    //     // Send request using GuzzleHTTP
    //     try {
    //         $client = new Client();
    //         $response = $client->post($apiUrl, [
    //             'json' => $payload,
    //             'headers' => [
    //                 'Content-Type' => 'application/json',
    //                 'Accept' => 'application/json'
    //             ]
    //         ]);

    //         // Handle response
    //         $statusCode = $response->getStatusCode();
    //         $body = $response->getBody()->getContents();

    //         // Return response to client
    //         return response()->json([
    //             'status_code' => $statusCode,
    //             'response_body' => $body
    //         ]);
    //     } catch (ClientException $e) {
    //         // Log error
    //         \Log::error('SMS API Error: ' . $e->getMessage());

    //         // Get error response from API
    //         $response = $e->getResponse();
    //         $statusCode = $response->getStatusCode();
    //         $body = $response->getBody()->getContents();

    //         // Return error response
    //         return response()->json([
    //             'status_code' => $statusCode,
    //             'error' => $body
    //         ], $statusCode);
    //     }
    // }

}
