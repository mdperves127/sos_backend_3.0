<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\Cache;

/**
 * Prevents infinite AffSell ↔ WooCommerce sync loops for a short window.
 *
 * Uses Cache::store() directly (not the Cache facade) so Stancl's
 * CacheTenancyBootstrapper does not wrap calls in tags() — file/database
 * drivers throw "This cache store does not support tagging."
 */
class WooCommerceLoopGuard {
    /** @var array<string, int> */
    private static array $memory = [];

    public static function mark( string $type, string|int $id, int $seconds = 45 ): void {
        $key = self::key( $type, $id );
        self::$memory[$key] = time() + $seconds;

        try {
            self::store()->put( $key, 1, $seconds );
        } catch ( \Throwable $e ) {
            // Memory flag is enough for same-request loop prevention.
        }
    }

    public static function isMarked( string $type, string|int $id ): bool {
        $key = self::key( $type, $id );

        if ( isset( self::$memory[$key] ) && self::$memory[$key] >= time() ) {
            return true;
        }

        try {
            return (bool) self::store()->has( $key );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public static function clear( string $type, string|int $id ): void {
        $key = self::key( $type, $id );
        unset( self::$memory[$key] );

        try {
            self::store()->forget( $key );
        } catch ( \Throwable $e ) {
            // ignore
        }
    }

    private static function store() {
        return Cache::store( config( 'cache.default', 'file' ) );
    }

    private static function key( string $type, string|int $id ): string {
        $tenant = function_exists( 'tenant' ) && tenant() ? (string) tenant( 'id' ) : 'central';

        return "woo_sync_guard:{$tenant}:{$type}:{$id}";
    }
}
