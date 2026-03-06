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
            $frontendBaseUrl = self::normalizeBaseUrl(
                config('app.maindomain') ?: config('app.redirecturl')
            );

            $configuredHost = parse_url($frontendBaseUrl, PHP_URL_HOST);
            $configuredScheme = parse_url($frontendBaseUrl, PHP_URL_SCHEME) ?: 'https';
            $subdomain = self::extractSubdomain(request()->getHost());

            if ($configuredHost) {
                $host = $subdomain ? ($subdomain . '.' . $configuredHost) : $configuredHost;
                return $configuredScheme . '://' . $host . '/';
            }

            $scheme = request()->secure() ? 'https' : 'http';
            return $scheme . '://' . request()->getHost() . '/';
        }

        return rtrim(config('app.redirecturl'), '/') . '/';
    }

    private static function normalizeBaseUrl(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }

    private static function extractSubdomain(string $host): ?string
    {
        $parts = array_values(array_filter(explode('.', $host)));

        if (count($parts) >= 3) {
            return $parts[0];
        }

        if (count($parts) === 2 && $parts[1] === 'localhost') {
            return $parts[0];
        }

        return null;
    }
}
