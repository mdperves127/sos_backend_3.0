<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stancl\Tenancy\Events;

/**
 * Temporary per-request step timing for POST /api/tenants/register.
 * Writes one "registration.timing" log line per request; never logs payload values.
 */
final class RegistrationTimer
{
    private static ?string $requestId = null;

    private static float $last = 0.0;

    /** @var array<string, int> */
    private static array $steps = [];

    /** @var array<int, array<string, mixed>> */
    private static array $cpanelCalls = [];

    private static int $queryCount = 0;

    private static float $queryMs = 0.0;

    public static function start( ?string $requestId ): void
    {
        if ( self::$requestId !== null ) {
            return;
        }

        self::$requestId = $requestId ?: 'gen-' . Str::uuid();
        self::$last      = microtime( true );
        self::$steps['boot_and_middleware_ms'] = defined( 'LARAVEL_START' )
            ? (int) round( ( self::$last - LARAVEL_START ) * 1000 )
            : -1;

        DB::listen( function ( $query ) {
            self::$queryCount++;
            self::$queryMs += (float) $query->time;
        } );

        Event::listen( Events\CreatingTenant::class, fn () => self::mark( 'before_tenant_insert_ms' ) );
        Event::listen( Events\CreatingDatabase::class, fn () => self::mark( 'tenant_row_insert_ms' ) );
        Event::listen( Events\DatabaseCreated::class, fn () => self::mark( 'create_database_ms' ) );
        Event::listen( Events\DatabaseMigrated::class, fn () => self::mark( 'migrate_database_ms' ) );
        Event::listen( Events\DatabaseSeeded::class, fn () => self::mark( 'seed_database_ms' ) );

        app()->terminating( fn () => self::flush() );
    }

    public static function mark( string $step ): void
    {
        if ( self::$requestId === null ) {
            return;
        }

        $now                  = microtime( true );
        self::$steps[ $step ] = (int) round( ( $now - self::$last ) * 1000 );
        self::$last           = $now;
    }

    public static function cpanelCall( string $path, string $host, int $httpCode, float $startedAt, bool $usable ): void
    {
        if ( self::$requestId === null ) {
            return;
        }

        self::$cpanelCalls[] = [
            'path'   => $path,
            'host'   => $host,
            'http'   => $httpCode,
            'ms'     => (int) round( ( microtime( true ) - $startedAt ) * 1000 ),
            'usable' => $usable,
        ];
    }

    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    private static function flush(): void
    {
        if ( self::$requestId === null ) {
            return;
        }

        self::mark( 'response_send_ms' );

        $opcache = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;

        Log::info( 'registration.timing', [
            'request_id'      => self::$requestId,
            'total_server_ms' => defined( 'LARAVEL_START' ) ? (int) round( ( microtime( true ) - LARAVEL_START ) * 1000 ) : -1,
            'steps'           => self::$steps,
            'cpanel_calls'    => self::$cpanelCalls,
            'cpanel_total_ms' => array_sum( array_column( self::$cpanelCalls, 'ms' ) ),
            'db_queries'      => self::$queryCount,
            'db_query_ms'     => (int) round( self::$queryMs ),
            'env'             => [
                'sapi'               => PHP_SAPI,
                'php'                => PHP_VERSION,
                'opcache_enabled'    => is_array( $opcache ) ? (bool) ( $opcache['opcache_enabled'] ?? false ) : false,
                'config_cached'      => app()->configurationIsCached(),
                'routes_cached'      => app()->routesAreCached(),
                'queue'              => config( 'queue.default' ),
                'mailer'             => config( 'mail.default' ),
                'cpanel_host'        => config( 'cpanel.host' ),
                'max_execution_time' => (int) ini_get( 'max_execution_time' ),
                'fastcgi_finish'     => function_exists( 'fastcgi_finish_request' ),
                'peak_memory_mb'     => (int) round( memory_get_peak_usage( true ) / 1048576 ),
                'code_mtime'         => date( 'c', (int) @filemtime( app_path( 'Services/TenantService.php' ) ) ),
            ],
        ] );
    }
}
