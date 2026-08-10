<?php

namespace App\Helper;

use App\Models\Tenant;
use App\Services\CustomDomainService;

class RedirectHelper
{
    /**
     * Frontend base URL for the current or given tenant.
     * Uses active custom domain first, otherwise the tenant subdomain.
     */
    public static function getRedirectUrl(): string
    {
        if ( function_exists( 'tenant' ) && tenant() ) {
            return self::getTenantRedirectUrl( tenant()->id );
        }

        return rtrim( config( 'app.redirecturl' ), '/' ) . '/';
    }

    public static function getTenantRedirectUrl( ?int $tenantId = null ): string
    {
        if ( ! $tenantId && function_exists( 'tenant' ) && tenant() ) {
            $tenantId = tenant()->id;
        }

        if ( ! $tenantId ) {
            return rtrim( config( 'app.redirecturl' ), '/' ) . '/';
        }

        return app( CustomDomainService::class )->frontendBaseUrl( (string) $tenantId );
    }

    /**
     * Prefer the frontend URL the user started payment from, when it belongs to the tenant.
     */
    public static function getPaymentRedirectUrl( ?int $tenantId, ?string $storedReturnUrl = null ): string
    {
        if ( $tenantId && $storedReturnUrl && self::isAllowedFrontendUrl( $tenantId, $storedReturnUrl ) ) {
            return rtrim( $storedReturnUrl, '/' ) . '/';
        }

        return self::getTenantRedirectUrl( $tenantId );
    }

    public static function captureFrontendOrigin(): ?string
    {
        $candidate = request()->header( 'Origin' );

        if ( ! $candidate ) {
            $referer = request()->header( 'Referer' );

            if ( $referer ) {
                $parts = parse_url( $referer );

                if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
                    $candidate = $parts['scheme'] . '://' . $parts['host'];

                    if ( ! empty( $parts['port'] ) ) {
                        $candidate .= ':' . $parts['port'];
                    }
                }
            }
        }

        if ( ! $candidate ) {
            return null;
        }

        return rtrim( $candidate, '/' ) . '/';
    }

    public static function appendPaymentReturnUrl( array $info ): array
    {
        $origin = self::captureFrontendOrigin();

        if ( $origin ) {
            $info['return_url'] = $origin;
        }

        return $info;
    }

    private static function isAllowedFrontendUrl( int $tenantId, string $url ): bool
    {
        $host = parse_url( $url, PHP_URL_HOST );

        if ( ! $host ) {
            return false;
        }

        return app( CustomDomainService::class )->isFrontendHostForTenant( (string) $tenantId, $host );
    }
}
