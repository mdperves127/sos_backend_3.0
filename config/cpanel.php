<?php

return [

    /*
    |--------------------------------------------------------------------------
    | cPanel API credentials
    |--------------------------------------------------------------------------
    |
    | Used for tenant subdomain + MySQL database provisioning.
    | Always read these via config('cpanel.*') — never env() in app code —
    | so values still work when `php artisan config:cache` is used.
    |
    | If the password contains special characters ( ) # " etc, quote it in .env:
    | CPANEL_PASSWORD="your-password-here"
    |
    */

    'user'     => env( 'CPANEL_USER' ),
    'password' => env( 'CPANEL_PASSWORD' ),
    'host'     => env( 'CPANEL_HOST' ),

    'main_domain' => env( 'MAIN_DOMAIN' ),

    // Document root for newly created tenant subdomains
    'tenant_root' => env( 'CPANEL_TENANT_ROOT', 'public_html/' ),

    // EasyApache PHP package, e.g. ea-php82
    'php_version' => env( 'CPANEL_PHP_VERSION', 'ea-php82' ),

];
