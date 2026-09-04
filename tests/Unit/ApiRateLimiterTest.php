<?php

namespace Tests\Unit;

use App\Providers\RouteServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionClass;
use Tests\TestCase;

class ApiRateLimiterTest extends TestCase
{
    public function test_public_get_limit_is_above_legacy_100_per_minute(): void
    {
        $provider = new RouteServiceProvider( $this->app );
        $method   = ( new ReflectionClass( $provider ) )->getMethod( 'configureRateLimiting' );
        $method->setAccessible( true );
        $method->invoke( $provider );

        $request = Request::create( '/api/settings', 'GET' );
        $limit   = RateLimiter::limiter( 'api' )( $request );

        $this->assertInstanceOf( Limit::class, $limit );
        $this->assertGreaterThanOrEqual( 12000, $limit->maxAttempts );
    }

    public function test_unauthenticated_writes_stay_stricter_than_public_gets(): void
    {
        $provider = new RouteServiceProvider( $this->app );
        $method   = ( new ReflectionClass( $provider ) )->getMethod( 'configureRateLimiting' );
        $method->setAccessible( true );
        $method->invoke( $provider );

        $get  = RateLimiter::limiter( 'api' )( Request::create( '/api/settings', 'GET' ) );
        $post = RateLimiter::limiter( 'api' )( Request::create( '/api/auth/login', 'POST' ) );

        $this->assertGreaterThan( $post->maxAttempts, $get->maxAttempts );
    }
}
