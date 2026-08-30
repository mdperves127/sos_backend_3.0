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
        // Primary switch: sandbox | live (legacy EPS_SANDBOX=true|false still works via EpsConfig).
        'mode'           => env( 'EPS_MODE' ),
        'legacy_sandbox' => env( 'EPS_SANDBOX' ),
        // Fallback when mode-specific base URL is not set.
        'base_url'       => env( 'EPS_BASE_URL', 'https://affsell.com' ),
        'base_urls'      => [
            'sandbox' => env( 'EPS_SANDBOX_BASE_URL', env( 'EPS_BASE_URL', 'https://affsell.com' ) ),
            'live'    => env( 'EPS_LIVE_BASE_URL', env( 'EPS_BASE_URL', 'https://affsell.com' ) ),
        ],
        // Real Laravel API (auto-complete / recovery).
        'api_url'       => env( 'EPS_API_URL', env( 'APP_URL', 'https://mdperves.info' ) ),
        // Optional: host under affsell.com that points to Laravel, e.g. pay.affsell.com
        'callback_host' => env( 'EPS_CALLBACK_HOST', '' ),
        'credentials'   => [
            'sandbox' => [
                'username'            => env( 'EPS_SANDBOX_USERNAME' ),
                'password'            => env( 'EPS_SANDBOX_PASSWORD' ),
                'hash_key'            => env( 'EPS_SANDBOX_HASH_KEY' ),
                'merchant_id'         => env( 'EPS_SANDBOX_MERCHANT_ID' ),
                'store_id'            => env( 'EPS_SANDBOX_STORE_ID' ),
                'transaction_type_id' => (int) env( 'EPS_SANDBOX_TRANSACTION_TYPE_ID', env( 'EPS_TRANSACTION_TYPE_ID', 1 ) ),
            ],
            'live' => [
                'username'            => env( 'EPS_LIVE_USERNAME' ),
                'password'            => env( 'EPS_LIVE_PASSWORD' ),
                'hash_key'            => env( 'EPS_LIVE_HASH_KEY' ),
                'merchant_id'         => env( 'EPS_LIVE_MERCHANT_ID' ),
                'store_id'            => env( 'EPS_LIVE_STORE_ID' ),
                'transaction_type_id' => (int) env( 'EPS_LIVE_TRANSACTION_TYPE_ID', env( 'EPS_TRANSACTION_TYPE_ID', 1 ) ),
            ],
            // Shared fallback when mode-specific vars are empty (backward compatible).
            'legacy' => [
                'username'            => env( 'EPS_USERNAME' ),
                'password'            => env( 'EPS_PASSWORD' ),
                'hash_key'            => env( 'EPS_HASH_KEY' ),
                'merchant_id'         => env( 'EPS_MERCHANT_ID' ),
                'store_id'            => env( 'EPS_STORE_ID' ),
                'transaction_type_id' => (int) env( 'EPS_TRANSACTION_TYPE_ID', 1 ),
            ],
        ],
    ],

    'steadfast' => [
        'base_url'        => env( 'STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1' ),
        // Bearer token configured in Steadfast merchant webhook settings.
        'webhook_bearer'  => env( 'STEADFAST_WEBHOOK_BEARER', env( 'STEADFAST_WEBHOOK_SECRET', '' ) ),
    ],

    'pathao' => [
        // true = sandbox, false = live (also accepts PATHAO_MODE=sandbox|live)
        'sandbox' => filter_var(
            env( 'PATHAO_SANDBOX', env( 'PATHAO_MODE', 'live' ) === 'sandbox' ),
            FILTER_VALIDATE_BOOLEAN
        ),
        // Must match the webhook secret configured in Pathao merchant dashboard.
        'webhook_secret' => env( 'PATHAO_WEBHOOK_SECRET', '' ),
    ],

    'redx' => [
        'sandbox' => env( 'REDX_MODE', 'live' ) === 'sandbox',
        // Query token for RedX webhook callback URL (?token=).
        'webhook_secret' => env( 'REDX_WEBHOOK_SECRET', '' ),
    ],

];
