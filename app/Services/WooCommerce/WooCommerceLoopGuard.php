<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\Cache;

/**
 * Prevents infinite AffSell ↔ WooCommerce sync loops for a short window.
 */
class WooCommerceLoopGuard {
    public static function mark( string $type, string|int $id, int $seconds = 45 ): void {
        Cache::put( self::key( $type, $id ), 1, $seconds );
    }

    public static function isMarked( string $type, string|int $id ): bool {
        return Cache::has( self::key( $type, $id ) );
    }

    public static function clear( string $type, string|int $id ): void {
        Cache::forget( self::key( $type, $id ) );
    }

    private static function key( string $type, string|int $id ): string {
        $tenant = function_exists( 'tenant' ) && tenant() ? (string) tenant( 'id' ) : 'central';

        return "woo_sync_guard:{$tenant}:{$type}:{$id}";
    }
}
