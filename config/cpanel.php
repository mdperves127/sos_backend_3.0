<?php

return [

    /*
    |--------------------------------------------------------------------------
    | cPanel API credentials
    |--------------------------------------------------------------------------
    |
    | Always read via config('cpanel.*') so values work with config:cache.
    |
    | When Laravel runs on the same cPanel server, prefer:
    |   CPANEL_HOST=127.0.0.1
    | Using the public domain often returns HTML (Cloudflare / login page)
    | instead of JSON → "Invalid JSON from cPanel".
    |
    | Quote passwords with special characters:
    |   CPANEL_PASSWORD="your-password-here"
    |
    | Optional API token (Security → API Tokens in cPanel) is more reliable
    | than account password:
    |   CPANEL_API_TOKEN=xxxxx
    |
    */

    'user'      => env( 'CPANEL_USER' ),
    'password'  => env( 'CPANEL_PASSWORD' ),
    'api_token' => env( 'CPANEL_API_TOKEN' ),

    // Prefer 127.0.0.1 on the same server; public domain often breaks UAPI.
    'host' => env( 'CPANEL_HOST', '127.0.0.1' ),
    'port' => (int) env( 'CPANEL_PORT', 2083 ),

    'main_domain' => env( 'MAIN_DOMAIN' ),

    'tenant_root' => env( 'CPANEL_TENANT_ROOT', 'public_html/' ),

    'php_version' => env( 'CPANEL_PHP_VERSION', 'ea-php82' ),

];
