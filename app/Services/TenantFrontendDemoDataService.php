<?php

namespace App\Services;

use App\Models\CmsSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Storefront demo-mode data from JSON files (no tenant catalog DB reads).
 */
class TenantFrontendDemoDataService
{
    public const RESOURCE_DIR = 'demo/storefront';

    public function isActive(): bool
    {
        if ( ! function_exists( 'tenant' ) || ! tenant() ) {
            return false;
        }

        try {
            if ( ! \Illuminate\Support\Facades\Schema::hasColumn( 'cms_settings', 'is_demo_active' ) ) {
                return false;
            }

            $cms = CmsSetting::query()->first( ['id', 'is_demo_active'] );

            return (bool) ( $cms?->is_demo_active );
        } catch ( Throwable $e ) {
            Log::warning( 'tenant_frontend.demo_setting_read_failed', [
                'tenant'  => tenant( 'id' ),
                'message' => $e->getMessage(),
            ] );

            return false;
        }
    }

    /**
     * @return Collection<int, object>
     */
    public function products(): Collection
    {
        return $this->asObjects( $this->loadJson( 'products.json' ) );
    }

    /**
     * @return Collection<int, object>
     */
    public function categories(): Collection
    {
        return $this->asObjects( $this->loadJson( 'categories.json' ) );
    }

    /**
     * @return Collection<int, object>
     */
    public function brands(): Collection
    {
        return $this->asObjects( $this->loadJson( 'brands.json' ) );
    }

    /**
     * @return Collection<int, object>
     */
    public function subcategories(): Collection
    {
        return $this->asObjects( $this->loadJson( 'subcategories.json' ) );
    }

    /**
     * Apply the same storefront filters used by MerchantFrontendController (in-memory).
     *
     * @param  Collection<int, object>  $products
     * @return Collection<int, object>
     */
    public function filterProducts( Collection $products, Request $request ): Collection
    {
        $categoryIds = $this->parseIdList(
            $this->firstFilled( $request, ['category_id', 'categoryIds'] )
        );
        $subcategoryIds = $this->parseIdList(
            $this->firstFilled( $request, ['sub_category_id', 'subcategory_id', 'subcategoryIds'] )
        );
        $brandIds = $this->parseIdList(
            $this->firstFilled( $request, ['brand_id', 'brandIds'] )
        );
        $productIds = $this->parseIdList(
            $this->firstFilled( $request, ['product_id', 'productIds'] )
        );
        $minPrice = $this->firstFilled( $request, ['min_price'] );
        $maxPrice = $this->firstFilled( $request, ['max_price'] );
        $search   = trim( (string) ( $this->firstFilled( $request, ['search'] ) ?? '' ) );

        return $products->filter( function ( $item ) use (
            $categoryIds,
            $subcategoryIds,
            $brandIds,
            $productIds,
            $minPrice,
            $maxPrice,
            $search
        ) {
            if ( $productIds !== [] && ! in_array( (string) data_get( $item, 'id' ), $productIds, true ) ) {
                return false;
            }

            if ( $categoryIds !== [] ) {
                $cid = (string) data_get( $item, 'category_id' );
                $mid = (string) data_get( $item, 'market_place_category_id' );
                if ( ! in_array( $cid, $categoryIds, true ) && ! in_array( $mid, $categoryIds, true ) ) {
                    return false;
                }
            }

            if ( $subcategoryIds !== [] ) {
                $sid = (string) data_get( $item, 'subcategory_id' );
                $mid = (string) data_get( $item, 'market_place_subcategory_id' );
                if ( ! in_array( $sid, $subcategoryIds, true ) && ! in_array( $mid, $subcategoryIds, true ) ) {
                    return false;
                }
            }

            if ( $brandIds !== [] ) {
                $bid = (string) data_get( $item, 'brand_id' );
                $mid = (string) data_get( $item, 'market_place_brand_id' );
                if ( ! in_array( $bid, $brandIds, true ) && ! in_array( $mid, $brandIds, true ) ) {
                    return false;
                }
            }

            if ( $minPrice !== null || $maxPrice !== null ) {
                $price = (float) ( data_get( $item, 'discount_price' ) ?? data_get( $item, 'selling_price' ) ?? 0 );
                if ( $minPrice !== null && $price < (float) $minPrice ) {
                    return false;
                }
                if ( $maxPrice !== null && $price > (float) $maxPrice ) {
                    return false;
                }
            }

            if ( $search !== '' ) {
                $hay = strtolower(
                    (string) data_get( $item, 'name' ) . ' '
                    . (string) data_get( $item, 'uniqid' ) . ' '
                    . (string) data_get( $item, 'sku' )
                );
                if ( ! str_contains( $hay, strtolower( $search ) ) ) {
                    return false;
                }
            }

            return true;
        } )->values();
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return Collection<int, object>
     */
    private function asObjects( array $rows ): Collection
    {
        return collect( $rows )
            ->map( fn ( $row ) => json_decode( json_encode( $row ) ) )
            ->filter( fn ( $row ) => is_object( $row ) )
            ->values();
    }

    /**
     * @return array<int, mixed>
     */
    private function loadJson( string $filename ): array
    {
        $tenantKey = (string) ( function_exists( 'tenant' ) && tenant() ? tenant( 'id' ) : 'central' );
        $cacheKey  = 'tenant_frontend_demo:' . $tenantKey . ':' . $filename;
        $mtime     = @filemtime( $this->path( $filename ) ) ?: 0;
        $cacheKey .= ':' . $mtime;

        try {
            // Use the plain file store — tenant CacheManager tags break Cache::remember()
            // when tenancy is initialized with the file driver.
            return Cache::store( 'file' )->remember( $cacheKey, 300, function () use ( $filename ) {
                return $this->readJsonFile( $filename );
            } );
        } catch ( Throwable $e ) {
            Log::warning( 'tenant_frontend.demo_json_load_failed', [
                'file'    => $filename,
                'message' => $e->getMessage(),
            ] );

            return $this->readJsonFile( $filename );
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function readJsonFile( string $filename ): array
    {
        $path = $this->path( $filename );
        if ( ! is_file( $path ) ) {
            Log::warning( 'tenant_frontend.demo_json_missing', ['file' => $filename] );

            return [];
        }

        $raw = file_get_contents( $path );
        if ( $raw === false || trim( $raw ) === '' ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            Log::warning( 'tenant_frontend.demo_json_invalid', ['file' => $filename] );

            return [];
        }

        if ( array_is_list( $decoded ) ) {
            return $decoded;
        }

        if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
            return $decoded['data'];
        }

        return [];
    }

    private function path( string $filename ): string
    {
        return resource_path( self::RESOURCE_DIR . '/' . ltrim( $filename, '/' ) );
    }

    /**
     * @return array<int, string>
     */
    private function parseIdList( mixed $value ): array
    {
        if ( $value === null || $value === '' ) {
            return [];
        }

        $parts = is_array( $value )
            ? array_map( static fn ( $id ) => trim( (string) $id ), $value )
            : array_map( 'trim', explode( ',', (string) $value ) );

        $ignored = ['', 'all', 'null', 'undefined', 'none'];

        return array_values( array_filter(
            $parts,
            static fn ( $id ) => ! in_array( strtolower( $id ), $ignored, true )
        ) );
    }

    private function firstFilled( Request $request, array $keys ): mixed
    {
        foreach ( $keys as $key ) {
            if ( ! $request->exists( $key ) ) {
                continue;
            }
            $value = $request->get( $key );
            if ( $value === null || $value === '' ) {
                continue;
            }
            if ( is_array( $value ) && $value === [] ) {
                continue;
            }

            return $value;
        }

        return null;
    }
}
