<?php

namespace App\Services\WooCommerce;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WoocommerceCredential;
use App\Services\WooCommerceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WooCommerceProductSyncService {
    public function __construct( protected WooCommerceService $woo ) {
    }

    public function importFromWoo( WoocommerceCredential $credential, ?array $wcProduct = null, ?int $wcProductId = null ): ?Product {
        if ( ! $credential->canSyncProducts( WoocommerceCredential::DIRECTION_WP_TO_AFFSELL ) ) {
            return null;
        }

        $client = $this->woo->forCredential( $credential );

        if ( $wcProduct === null ) {
            if ( ! $wcProductId ) {
                return null;
            }
            $wcProduct = $client->get( 'products/' . $wcProductId )['data'] ?? null;
        }

        if ( ! is_array( $wcProduct ) || empty( $wcProduct['id'] ) ) {
            return null;
        }

        if ( WooCommerceLoopGuard::isMarked( 'product', $wcProduct['id'] ) ) {
            return Product::where( 'wc_product_id', 'WC-' . $wcProduct['id'] )->first();
        }

        try {
            $product = DB::connection( 'tenant' )->transaction( function () use ( $wcProduct, $credential ) {
                return $this->upsertLocalProduct( $wcProduct, $credential );
            } );

            WooCommerceSyncLogger::log(
                $credential,
                'product',
                WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
                'success',
                'Product synced from WooCommerce',
                'product',
                (string) $product->id,
                (string) $wcProduct['id'],
                null,
                ['name' => $product->name, 'sku' => $product->sku, 'qty' => $product->qty]
            );
            WooCommerceSyncLogger::markCredentialSynced( $credential, true );

            return $product;
        } catch ( \Throwable $e ) {
            Log::error( 'woo.product_import_failed', ['error' => $e->getMessage(), 'wc_id' => $wcProduct['id'] ?? null] );
            WooCommerceSyncLogger::log(
                $credential,
                'product',
                WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
                'failed',
                $e->getMessage(),
                'product',
                null,
                (string) ( $wcProduct['id'] ?? '' )
            );
            WooCommerceSyncLogger::markCredentialSynced( $credential, false, $e->getMessage() );

            throw $e;
        }
    }

    public function syncAllFromWoo( WoocommerceCredential $credential ): array {
        if ( ! $credential->canSyncProducts( WoocommerceCredential::DIRECTION_WP_TO_AFFSELL ) ) {
            return ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'message' => 'Product sync disabled or direction blocks WP→AffSell'];
        }

        $client   = $this->woo->forCredential( $credential );
        $products = $client->paginateAll( 'products', ['status' => 'publish'] );
        $stats    = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ( $products as $wcProduct ) {
            try {
                $before = Product::where( 'wc_product_id', 'WC-' . $wcProduct['id'] )->exists();
                $this->importFromWoo( $credential, $wcProduct );
                $before ? $stats['skipped']++ : $stats['imported']++;
            } catch ( \Throwable $e ) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    public function pushToWoo( WoocommerceCredential $credential, Product $product ): ?array {
        if ( ! $credential->canSyncProducts( WoocommerceCredential::DIRECTION_AFFSELL_TO_WP ) ) {
            return null;
        }

        if ( WooCommerceLoopGuard::isMarked( 'product_local', $product->id ) ) {
            return null;
        }

        $client  = $this->woo->forCredential( $credential );
        $payload = $this->buildWooPayload( $product );
        $wcId    = $this->parseWcId( $product->wc_product_id );

        try {
            if ( $wcId ) {
                $response = $client->put( 'products/' . $wcId, $payload );
            } else {
                $response = $client->post( 'products', $payload );
                $wcId     = $response['data']['id'] ?? null;
                if ( $wcId ) {
                    $product->wc_product_id = 'WC-' . $wcId;
                    $product->product_type  = 'woocommerce';
                }
            }

            if ( Schema::connection( 'tenant' )->hasColumn( 'products', 'wc_last_synced_at' ) ) {
                $product->wc_last_synced_at = now();
            }
            $product->save();

            WooCommerceLoopGuard::mark( 'product', (string) $wcId );

            WooCommerceSyncLogger::log(
                $credential,
                'product',
                WoocommerceCredential::DIRECTION_AFFSELL_TO_WP,
                'success',
                'Product pushed to WooCommerce',
                'product',
                (string) $product->id,
                (string) $wcId,
                null,
                $payload
            );
            WooCommerceSyncLogger::markCredentialSynced( $credential, true );

            return $response['data'] ?? null;
        } catch ( \Throwable $e ) {
            WooCommerceSyncLogger::log(
                $credential,
                'product',
                WoocommerceCredential::DIRECTION_AFFSELL_TO_WP,
                'failed',
                $e->getMessage(),
                'product',
                (string) $product->id,
                $wcId ? (string) $wcId : null
            );
            WooCommerceSyncLogger::markCredentialSynced( $credential, false, $e->getMessage() );
            throw $e;
        }
    }

    public function deleteLocalByWcId( WoocommerceCredential $credential, int $wcProductId ): void {
        if ( ! $credential->canSyncProducts( WoocommerceCredential::DIRECTION_WP_TO_AFFSELL ) ) {
            return;
        }

        $product = Product::where( 'wc_product_id', 'WC-' . $wcProductId )->first();
        if ( ! $product ) {
            return;
        }

        $product->status = 'inactive';
        $product->save();

        WooCommerceSyncLogger::log(
            $credential,
            'product',
            WoocommerceCredential::DIRECTION_WP_TO_AFFSELL,
            'success',
            'Product marked inactive after WooCommerce delete',
            'product',
            (string) $product->id,
            (string) $wcProductId
        );
    }

    protected function upsertLocalProduct( array $wcProduct, WoocommerceCredential $credential ): Product {
        $wcId    = (int) $wcProduct['id'];
        $product = Product::where( 'wc_product_id', 'WC-' . $wcId )->first() ?: new Product();

        $vendorId = function_exists( 'vendorId' ) ? vendorId() : ( Auth::id() ?: $credential->vendor_id );

        $categoryId  = $this->resolveCategoryId( $wcProduct );
        $brandId     = $this->resolveBrandId();
        $warehouseId = $this->resolveWarehouseId( $vendorId );
        $supplierId  = $this->resolveSupplierId( $vendorId );

        $regular = $wcProduct['regular_price'] !== '' ? (float) $wcProduct['regular_price'] : 0.0;
        $sale    = $wcProduct['sale_price'] !== '' && $wcProduct['sale_price'] !== null
            ? (float) $wcProduct['sale_price']
            : null;

        $product->fill( [
            'product_type'       => 'woocommerce',
            'wc_product_id'      => 'WC-' . $wcId,
            'category_id'        => $categoryId,
            'brand_id'           => $brandId,
            'warehouse_id'       => $warehouseId,
            'supplier_id'        => $supplierId,
            'user_id'            => $vendorId,
            'vendor_id'          => $vendorId,
            'name'               => $wcProduct['name'] ?? 'Untitled',
            'slug'               => $wcProduct['slug'] ?? Str::slug( $wcProduct['name'] ?? uniqid( 'wc-' ) ),
            'short_description'  => $wcProduct['short_description'] ?? null,
            'long_description'   => $wcProduct['description'] ?? null,
            'sku'                => $wcProduct['sku'] ?: ( $product->sku ?: generateSKU() ),
            'selling_price'      => $sale !== null && $sale > 0 ? $sale : $regular,
            'original_price'     => $regular,
            'discount_price'     => $sale,
            'qty'                => ! empty( $wcProduct['manage_stock'] ) ? (int) ( $wcProduct['stock_quantity'] ?? 0 ) : (int) ( $product->qty ?? 0 ),
            'status'             => ( $wcProduct['status'] ?? '' ) === 'publish' ? 'active' : 'inactive',
            'meta_title'         => $wcProduct['name'] ?? null,
            'meta_description'   => strip_tags( (string) ( $wcProduct['short_description'] ?? '' ) ),
            'selling_type'       => 'single',
            'discount_type'      => 'flat',
            'discount_rate'      => ( $sale !== null && $regular > $sale ) ? round( $regular - $sale, 2 ) : 0,
            'is_affiliate'       => $product->exists ? $product->is_affiliate : 0,
            'uniqid'             => $product->uniqid ?: uniqid(),
        ] );

        if ( Schema::connection( 'tenant' )->hasColumn( 'products', 'wc_last_synced_at' ) ) {
            $product->wc_last_synced_at = now();
        }

        $this->syncImages( $product, $wcProduct['images'] ?? [] );
        $product->save();

        if ( ( $wcProduct['type'] ?? '' ) === 'variable' ) {
            $this->syncVariations( $credential, $product, $wcId );
        }

        WooCommerceLoopGuard::mark( 'product_local', $product->id );

        return $product->fresh();
    }

    protected function syncVariations( WoocommerceCredential $credential, Product $product, int $wcProductId ): void {
        if ( ! Schema::connection( 'tenant' )->hasTable( 'product_variants' ) ) {
            return;
        }

        $client     = $this->woo->forCredential( $credential );
        $variations = $client->paginateAll( 'products/' . $wcProductId . '/variations' );

        foreach ( $variations as $variation ) {
            $attrs   = $variation['attributes'] ?? [];
            $colorId = null;
            $sizeId  = null;

            foreach ( $attrs as $attr ) {
                $name  = strtolower( (string) ( $attr['name'] ?? '' ) );
                $value = (string) ( $attr['option'] ?? '' );
                if ( $value === '' ) {
                    continue;
                }
                if ( str_contains( $name, 'color' ) || str_contains( $name, 'colour' ) ) {
                    $colorId = $this->findOrCreateColor( $value, $product->vendor_id )->id;
                }
                if ( str_contains( $name, 'size' ) ) {
                    $sizeId = $this->findOrCreateSize( $value, $product->vendor_id )->id;
                }
            }

            $variantQuery = ProductVariant::where( 'product_id', $product->id );
            if ( Schema::connection( 'tenant' )->hasColumn( 'product_variants', 'wc_variation_id' ) ) {
                $variant = ( clone $variantQuery )->where( 'wc_variation_id', (string) $variation['id'] )->first();
            } else {
                $variant = null;
            }

            if ( ! $variant ) {
                $variant = new ProductVariant( [
                    'product_id' => $product->id,
                    'user_id'    => $product->user_id,
                ] );
            }

            $variant->color_id = $colorId;
            $variant->size_id  = $sizeId;
            $variant->qty      = (int) ( $variation['stock_quantity'] ?? 0 );
            $variant->rate     = (float) ( $variation['sale_price'] ?: $variation['regular_price'] ?: 0 );
            if ( Schema::connection( 'tenant' )->hasColumn( 'product_variants', 'wc_variation_id' ) ) {
                $variant->wc_variation_id = (string) $variation['id'];
            }
            $variant->save();
        }
    }

    protected function syncImages( Product $product, array $images ): void {
        if ( $images === [] ) {
            if ( ! $product->image ) {
                $product->image = 'uploads/product/no-image.png';
            }

            return;
        }

        foreach ( $images as $loop => $image ) {
            $src = $image['src'] ?? null;
            if ( ! $src ) {
                continue;
            }
            $path = fileUploadFromUrl( $src, 'uploads/product' );
            if ( ! $path ) {
                continue;
            }
            if ( $loop === 0 ) {
                $product->image = $path;
                continue;
            }
            if ( $product->exists ) {
                ProductImage::firstOrCreate( [
                    'product_id' => $product->id,
                    'image'      => $path,
                ] );
            }
        }
    }

    protected function buildWooPayload( Product $product ): array {
        $regular = (float) ( $product->original_price ?: $product->selling_price ?: 0 );
        $sale    = $product->discount_price !== null ? (float) $product->discount_price : null;

        $payload = [
            'name'              => $product->name,
            'type'              => 'simple',
            'regular_price'     => (string) $regular,
            'description'       => (string) ( $product->long_description ?? '' ),
            'short_description' => (string) ( $product->short_description ?? '' ),
            'sku'               => (string) ( $product->sku ?? '' ),
            'manage_stock'      => true,
            'stock_quantity'    => (int) ( $product->qty ?? 0 ),
            'status'            => $product->status === 'active' ? 'publish' : 'draft',
        ];

        if ( $sale !== null && $sale > 0 && $sale < $regular ) {
            $payload['sale_price'] = (string) $sale;
        }

        return $payload;
    }

    protected function parseWcId( ?string $wcProductId ): ?int {
        if ( ! $wcProductId ) {
            return null;
        }
        if ( str_starts_with( $wcProductId, 'WC-' ) ) {
            return (int) substr( $wcProductId, 3 );
        }

        return is_numeric( $wcProductId ) ? (int) $wcProductId : null;
    }

    protected function resolveCategoryId( array $wcProduct ): ?int {
        $categories = $wcProduct['categories'] ?? [];
        if ( ! is_array( $categories ) || $categories === [] ) {
            return null;
        }
        $name = ucwords( (string) ( $categories[0]['name'] ?? 'General' ) );
        $slug = strtolower( (string) ( $categories[0]['slug'] ?? Str::slug( $name ) ) );

        $category = Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'status' => 'active']
        );

        return $category->id;
    }

    protected function resolveBrandId(): int {
        $brand = Brand::firstOrCreate(
            ['slug' => 'woocommerce_brand'],
            [
                'name'       => 'Woocommerce Brand',
                'user_id'    => 1,
                'created_by' => 'admin',
                'status'     => 'active',
            ]
        );

        return $brand->id;
    }

    protected function resolveWarehouseId( $vendorId ): ?int {
        $warehouse = Warehouse::where( 'vendor_id', $vendorId )->where( 'slug', 'woocommerce' )->first();
        if ( ! $warehouse ) {
            $warehouse              = new Warehouse();
            $warehouse->user_id     = Auth::id() ?: $vendorId;
            $warehouse->name        = 'Woocommerce';
            $warehouse->slug        = 'woocommerce';
            $warehouse->description = 'Woocommerce';
            $warehouse->status      = 'active';
            $warehouse->vendor_id   = $vendorId;
            $warehouse->save();
        }

        return $warehouse->id;
    }

    protected function resolveSupplierId( $vendorId ): ?int {
        $supplier = Supplier::where( 'vendor_id', $vendorId )->where( 'supplier_slug', 'woocommerce-supplier' )->first();
        if ( ! $supplier ) {
            $supplier                = new Supplier();
            $supplier->supplier_name = 'Woocommerce Supplier';
            $supplier->supplier_slug = 'woocommerce-supplier';
            $supplier->supplier_id   = generateRandomString( 8 );
            $supplier->business_name = 'Woocommerce Supplier';
            $supplier->status        = 'active';
            $supplier->vendor_id     = $vendorId;
            $supplier->save();
        }

        return $supplier->id;
    }

    protected function findOrCreateColor( string $name, $vendorId ): Color {
        return Color::firstOrCreate(
            ['user_id' => $vendorId, 'name' => $name],
            ['status' => 'active', 'slug' => Str::slug( $name ), 'vendor_id' => $vendorId]
        );
    }

    protected function findOrCreateSize( string $name, $vendorId ): Size {
        return Size::firstOrCreate(
            ['user_id' => $vendorId, 'name' => $name],
            ['status' => 'active', 'slug' => Str::slug( $name ), 'vendor_id' => $vendorId]
        );
    }
}
