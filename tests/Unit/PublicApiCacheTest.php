<?php

namespace Tests\Unit;

use App\Support\PublicApiCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicApiCacheTest extends TestCase
{
    public function test_remember_works_with_file_driver_without_tags(): void
    {
        config( [ 'cache.default' => 'file' ] );
        Cache::flush();

        $value = PublicApiCache::remember( 'unit-test-key', function () {
            return [ 'ok' => true ];
        } );

        $this->assertSame( [ 'ok' => true ], $value );
        $this->assertSame( [ 'ok' => true ], PublicApiCache::remember( 'unit-test-key', function () {
            return [ 'ok' => false ];
        } ) );
    }
}
