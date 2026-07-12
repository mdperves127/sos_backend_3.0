<?php

namespace App\Helper;

use App\Models\Tenant;

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
            return self::buildTenantBaseUrl(
                request()->getHost(),
                request()->secure() ? 'https' : 'http'
            );
        }

        return rtrim(config('app.redirecturl'), '/') . '/';
    }

    /**
     * Resolve a tenant frontend base URL from tenant id, current tenant context, or request host.
     */
    public static function getTenantRedirectUrl( ?int $tenantId = null ): string {
        if ( function_exists( 'tenant' ) && tenant() && request() ) {
            return self::getRedirectUrl();
        }

        if ( $tenantId ) {
            $tenant = Tenant::on( 'mysql' )->find( $tenantId );
            $domain = $tenant?->domains()->value( 'domain' );

            if ( $domain ) {
                $configuredScheme = parse_url( self::normalizeBaseUrl( config( 'app.redirecturl' ) ), PHP_URL_SCHEME ) ?: 'https';

                return $configuredScheme . '://' . $domain . '/';
            }
        }

        return self::getRedirectUrl();
    }

    private static function buildTenantBaseUrl( string $host, ?string $fallbackScheme = null ): string {
        $frontendBaseUrl = self::normalizeBaseUrl(
            config( 'app.maindomain' ) ?: config( 'app.redirecturl' )
        );

        $configuredHost   = parse_url( $frontendBaseUrl, PHP_URL_HOST );
        $configuredScheme = parse_url( $frontendBaseUrl, PHP_URL_SCHEME ) ?: ( $fallbackScheme ?: 'https' );
        $subdomain        = self::extractSubdomain( $host );

        if ( $configuredHost ) {
            $resolvedHost = $subdomain ? ( $subdomain . '.' . $configuredHost ) : $configuredHost;

            return $configuredScheme . '://' . $resolvedHost . '/';
        }

        $scheme = $fallbackScheme ?: 'https';

        return $scheme . '://' . $host . '/';
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
