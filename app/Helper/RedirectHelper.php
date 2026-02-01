<?php

namespace App\Helper;

class RedirectHelper
{
    /**
     * Get redirect URL - when in tenant context returns tenant subdomain URL dynamically.
     *
     * @return string Base URL for redirects (with trailing slash)
     */
    public static function getRedirectUrl(): string
    {
        if (function_exists('tenant') && tenant() && request()) {
            $scheme = request()->secure() ? 'https' : 'http';
            $host   = request()->getHost();

            return $scheme . '://' . $host . '/';
        }

        return rtrim(config('app.redirecturl'), '/') . '/';
    }
}
