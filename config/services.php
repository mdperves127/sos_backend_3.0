<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'mimsms' => [
        'url'              => env('MIM_SMS_API_URL', 'https://api.mimsms.com/api/SmsSending/SMS'),
        'username'         => env('MIM_SMS_USERNAME'),
        'api_key'          => env('MIM_SMS_API_KEY'),
        'sender_name'      => env('MIM_SMS_SENDER_NAME'),
        'transaction_type' => env('MIM_SMS_TRANSACTION_TYPE', 'T'),
    ],

    'eps' => [
        'sandbox'             => filter_var( env( 'EPS_SANDBOX', true ), FILTER_VALIDATE_BOOLEAN ),
        // MUST match EPS merchant portal BaseUrl exactly (currently affsell.com).
        'base_url'            => env( 'EPS_BASE_URL', 'https://affsell.com' ),
        // Real Laravel API (auto-complete / recovery).
        'api_url'             => env( 'EPS_API_URL', env( 'APP_URL', 'https://mdperves.info' ) ),
        // Optional: host under affsell.com that points to Laravel, e.g. pay.affsell.com
        // If set, recharge/renew return here and credit immediately.
        'callback_host'       => env( 'EPS_CALLBACK_HOST', '' ),
        'merchant_id'         => env( 'EPS_MERCHANT_ID' ),
        'store_id'            => env( 'EPS_STORE_ID' ),
        'username'            => env( 'EPS_USERNAME' ),
        'password'            => env( 'EPS_PASSWORD' ),
        'hash_key'            => env( 'EPS_HASH_KEY' ),
        'transaction_type_id' => (int) env( 'EPS_TRANSACTION_TYPE_ID', 1 ),
    ],

    'steadfast' => [
        'base_url' => env( 'STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1' ),
    ],

    'pathao' => [
        // true = sandbox, false = live (also accepts PATHAO_MODE=sandbox|live)
        'sandbox' => filter_var(
            env( 'PATHAO_SANDBOX', env( 'PATHAO_MODE', 'live' ) === 'sandbox' ),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

];
