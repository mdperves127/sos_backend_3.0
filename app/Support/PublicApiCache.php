<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class PublicApiCache
{
    public static function ttl(): int
    {
        return max( 1, (int) env( 'PUBLIC_API_CACHE_TTL', 60 ) );
    }

    public static function remember( string $name, callable $callback ): mixed
    {
        return self::store()->remember( self::key( $name ), self::ttl(), $callback );
    }

    public static function bump(): void
    {
        self::store()->forever( 'public:marketing_version', self::version() + 1 );
    }

    public static function key( string $name ): string
    {
        return 'public:v' . self::version() . ':' . $name;
    }

    /**
     * Use the named store directly so Stancl's TenantCacheManager does not
     * wrap calls in tags() — file/database drivers cannot tag and would 500.
     * Keys already include tenant id where needed for isolation.
     */
    private static function store()
    {
        return Cache::store( config( 'cache.default', 'file' ) );
    }

    private static function version(): int
    {
        return max( 1, (int) self::store()->get( 'public:marketing_version', 1 ) );
    }
}
