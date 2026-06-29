<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;
use App\Models\ProductDetails;
use App\Models\Tenant;
use App\Models\User;
use App\Models\MPCategory;
use App\Models\MPSubCategory;
use App\Models\MPBrand;
use Illuminate\Support\Facades\DB;
use App\Models\ServiceContent;
use App\Models\Banner;
use App\Models\CmsSetting;
use App\Models\TenantContactFormData;
use App\Models\Order;
use App\Models\Offer;
use App\Models\Color;
use App\Models\Size;
use App\Models\News;
use App\Models\NCategory;
use App\Models\UserSubscription;
use App\Models\ProductRating;


class MerchantFrontendController extends Controller
{
    /**
     * Optional ?limit= on product list endpoints. Returns null when omitted (keep current query size).
     */
    private function requestListLimit( Request $request ): ?int {
        if ( !$request->has( 'limit' ) || $request->get( 'limit' ) === '' || $request->get( 'limit' ) === null ) {
            return null;
        }
        $limit = (int) $request->get( 'limit' );

        return $limit > 0 ? $limit : null;
    }

    /**
     * Pagination page size: ?limit= overrides ?per_page=; default matches previous behavior.
     */
    private function requestPerPage( Request $request, int $default = 10 ): int {
        $limit = $this->requestListLimit( $request );
        if ( $limit !== null ) {
            return $limit;
        }
        $perPage = (int) $request->get( 'per_page', $default );

        return $perPage > 0 ? $perPage : $default;
    }

    private function applyListLimitToQuery( $query, Request $request ) {
        $limit = $this->requestListLimit( $request );
        if ( $limit !== null ) {
            $query->limit( $limit );
        }

        return $query;
    }

    private function applyWebsiteVisibleProductFilter( $query ) {
        return $query->shownOnWebsite();
    }

    private function paginateProductCollection( Request $request, $products, int $defaultPerPage = 10 ): array {
        $page    = max( 1, (int) $request->get( 'page', 1 ) );
        $perPage = $this->requestPerPage( $request, $defaultPerPage );
        $offset  = ( $page - 1 ) * $perPage;

        $total             = $products->count();
        $paginatedProducts = $products->slice( $offset, $perPage );
        $lastPage          = (int) max( 1, ceil( $total / $perPage ) );

        $path        = $request->url();
        $queryParams = $request->query();
        $buildUrl    = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;

            return $path . '?' . http_build_query( $queryParams );
        };

        $links   = [];
        $links[] = [
            'url'    => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'label'  => '&laquo; Previous',
            'active' => false,
        ];

        for ( $i = 1; $i <= $lastPage; $i++ ) {
            $links[] = [
                'url'    => $buildUrl( $i ),
                'label'  => (string) $i,
                'active' => $i == $page,
            ];
        }

        $links[] = [
            'url'    => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'label'  => 'Next &raquo;',
            'active' => false,
        ];

        return [
            'data'             => $paginatedProducts->values(),
            'current_page'     => $page,
            'per_page'         => $perPage,
            'limit'            => $this->requestListLimit( $request ),
            'total'            => $total,
            'last_page'        => $lastPage,
            'from'             => $total > 0 ? $offset + 1 : null,
            'to'               => $total > 0 ? min( $offset + $perPage, $total ) : null,
            'path'             => $path,
            'first_page_url'   => $buildUrl( 1 ),
            'last_page_url'    => $buildUrl( $lastPage ),
            'prev_page_url'    => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url'    => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'links'            => $links,
        ];
    }

    private function buildProductReviewPayload( int $productId, ?string $connection = null ): array {
        $ratingQuery = visibleProductRatingsQuery( $connection )->where( 'product_id', $productId );

        return [
            'average_rating'  => round( (float) ( clone $ratingQuery )->avg( 'rating' ), 1 ),
            'total_reviews'   => ( clone $ratingQuery )->count(),
            'reviews_preview' => ( clone $ratingQuery )
                ->with( 'user:id,name' )
                ->latest()
                ->limit( 5 )
                ->get( ['id', 'user_id', 'product_id', 'rating', 'comment', 'created_at'] ),
        ];
    }

    public function products(Request $request)
    {
        // Get filter parameters
        $categoryId = $request->get('category_id');
        // Handle comma-separated category IDs
        $categoryIds = $categoryId ? array_filter(array_map('trim', explode(',', $categoryId))) : [];
        $minPrice = $request->get('min_price');
        $maxPrice = $request->get('max_price');
        $colorId = $request->get('color_id');
        $sizeId = $request->get('size_id');

        if(tenant()->type == 'dropshipper') {
            // Step 1: Get all ProductDetails from current tenant's database
            $allProductDetails = ProductDetails::where('status', 1)->get();

            // Step 2: For each ProductDetails, get tenant_id and load product from that tenant's database
            $products = collect();

            foreach ( $allProductDetails as $productDetail ) {
                // Get tenant_id from ProductDetails record (this is the merchant tenant ID)
                $storedTenantId = $productDetail->tenant_id;

                if ( !$storedTenantId || !$productDetail->product_id ) {
                    continue;
                }

                // Lookup tenant from central database
                $tenant = Tenant::on( 'mysql' )->find( $storedTenantId );
                if ( !$tenant ) {
                    continue;
                }

                $connectionName = 'tenant_' . $tenant->id;
                $databaseName   = 'affsellc_' . $tenant->id;

                // Configure connection to the tenant database specified by tenant_id
                config( [
                    'database.connections.' . $connectionName => [
                        'driver'   => 'mysql',
                        'host'     => config( 'database.connections.mysql.host' ),
                        'port'     => config( 'database.connections.mysql.port' ),
                        'database' => $databaseName,
                        'username' => config( 'database.connections.mysql.username' ),
                        'password' => config( 'database.connections.mysql.password' ),
                        'charset'  => 'utf8mb4',
                        'collation'=> 'utf8mb4_unicode_ci',
                        'strict'   => false,
                    ],
                ] );
                DB::purge( $connectionName );

                // Build query with filters
                $productQuery = $this->applyWebsiteVisibleProductFilter(
                    Product::on( $connectionName )
                        ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails', 'vendor' )
                );

                // Apply category filter (support multiple categories)
                if ( !empty( $categoryIds ) ) {
                    $productQuery->where( function( $q ) use ( $categoryIds ) {
                        $q->whereIn( 'category_id', $categoryIds )
                          ->orWhereIn( 'market_place_category_id', $categoryIds );
                    } );
                }

                // Apply price range filter
                if ( $minPrice !== null || $maxPrice !== null ) {
                    $productQuery->where( function( $q ) use ( $minPrice, $maxPrice ) {
                        // Use discount_price if available, otherwise selling_price
                        $q->whereRaw( 'COALESCE(discount_price, selling_price) >= ?', [$minPrice ?? 0] )
                          ->whereRaw( 'COALESCE(discount_price, selling_price) <= ?', [$maxPrice ?? 999999999] );
                    } );
                }

                // Apply color filter (product_variants has product_id, color_id; colors table: colors)
                if ( $colorId ) {
                    $productQuery->whereExists( function ( $q ) use ( $colorId ) {
                        $q->select( DB::raw( 1 ) )
                            ->from( 'product_variants' )
                            ->whereColumn( 'product_variants.product_id', 'products.id' )
                            ->where( 'product_variants.color_id', (int) $colorId )
                            ->whereNull( 'product_variants.deleted_at' );
                    } );
                }

                // Apply size filter (product_variants has product_id, size_id; sizes table: sizes)
                if ( $sizeId ) {
                    $productQuery->whereExists( function ( $q ) use ( $sizeId ) {
                        $q->select( DB::raw( 1 ) )
                            ->from( 'product_variants' )
                            ->whereColumn( 'product_variants.product_id', 'products.id' )
                            ->where( 'product_variants.size_id', (int) $sizeId )
                            ->whereNull( 'product_variants.deleted_at' );
                    } );
                }

                // Load product
                try {
                    $product = $productQuery->find( $productDetail->product_id );
                } catch ( \Exception $e ) {
                    // Skip this product if there's an error
                    continue;
                }

                if ( $product ) {
                    $products->push( $product );
                }
            }

        } else {
            // Build query with filters for non-dropshippers
            $productQuery = $this->applyWebsiteVisibleProductFilter(
                Product::where( 'status', 'active' )
                    ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails' )
            );

            // Apply category filter (support multiple categories)
            if ( !empty( $categoryIds ) ) {
                $productQuery->where( function( $q ) use ( $categoryIds ) {
                    $q->whereIn( 'category_id', $categoryIds )
                      ->orWhereIn( 'market_place_category_id', $categoryIds );
                } );
            }

            // Apply price range filter
            if ( $minPrice !== null || $maxPrice !== null ) {
                $productQuery->where( function( $q ) use ( $minPrice, $maxPrice ) {
                    // Use discount_price if available, otherwise selling_price
                    $q->whereRaw( 'COALESCE(discount_price, selling_price) >= ?', [$minPrice ?? 0] )
                      ->whereRaw( 'COALESCE(discount_price, selling_price) <= ?', [$maxPrice ?? 999999999] );
                } );
            }

            // Apply color filter (product_variants has product_id, color_id; colors table: colors)
            if ( $colorId ) {
                $productQuery->whereExists( function ( $q ) use ( $colorId ) {
                    $q->select( DB::raw( 1 ) )
                        ->from( 'product_variants' )
                        ->whereColumn( 'product_variants.product_id', 'products.id' )
                        ->where( 'product_variants.color_id', (int) $colorId )
                        ->whereNull( 'product_variants.deleted_at' );
                } );
            }

            // Apply size filter (product_variants has product_id, size_id; sizes table: sizes)
            if ( $sizeId ) {
                $productQuery->whereExists( function ( $q ) use ( $sizeId ) {
                    $q->select( DB::raw( 1 ) )
                        ->from( 'product_variants' )
                        ->whereColumn( 'product_variants.product_id', 'products.id' )
                        ->where( 'product_variants.size_id', (int) $sizeId )
                        ->whereNull( 'product_variants.deleted_at' );
                } );
            }

            $products = $productQuery->get();
        }

        return response()->json( $this->paginateProductCollection( $request, $products ) );
    }

    public function product( Request $request, $slug )
    {
        $reviewConnection = null;

        if(tenant()->type == 'dropshipper') {
            // Step 1: Get ProductDetails from current tenant's database by ID
            $productDetail = ProductDetails::where('slug', $slug)->first();

            if ( !$productDetail ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product not found',
                ] );
            }

            // Get tenant_id from ProductDetails record (this is the merchant tenant ID)
            $storedTenantId = $productDetail->tenant_id;

            if ( !$storedTenantId || !$productDetail->product_id ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product tenant information not found',
                ] );
            }

            // Lookup tenant from central database
            $tenant = Tenant::on( 'mysql' )->find( $storedTenantId );
            if ( !$tenant ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Tenant not found',
                ] );
            }

            $connectionName = 'tenant_' . $tenant->id;
            $databaseName   = 'affsellc_' . $tenant->id;

            // Configure connection to the tenant database specified by tenant_id
            config( [
                'database.connections.' . $connectionName => [
                    'driver'   => 'mysql',
                    'host'     => config( 'database.connections.mysql.host' ),
                    'port'     => config( 'database.connections.mysql.port' ),
                    'database' => $databaseName,
                    'username' => config( 'database.connections.mysql.username' ),
                    'password' => config( 'database.connections.mysql.password' ),
                    'charset'  => 'utf8mb4',
                    'collation'=> 'utf8mb4_unicode_ci',
                    'strict'   => false,
                ],
            ] );
            DB::purge( $connectionName );
            $reviewConnection = $connectionName;

            // Load product from the tenant database using product_id
            $product = $this->applyWebsiteVisibleProductFilter(
                Product::on( $connectionName )
                    ->with( [
                        'category',
                        'subcategory',
                        'brand',
                        'productImage',
                        'productdetails',
                        'vendor',
                        'productVariant.size',
                        'productVariant.unit',
                        'productVariant.color',
                        'productVariant.product',
                    ] )
                    ->where( 'slug', $slug )
            )->first();

            if ( !$product ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product not found in tenant database',
                ] );
            }

            $relatedQuery = $this->applyWebsiteVisibleProductFilter(
                Product::on( $connectionName )
                    ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails', 'vendor' )
                    ->where( 'market_place_category_id', $product->market_place_category_id )
                    ->where( 'id', '!=', $product->id )
            );
            $related_products = $this->applyListLimitToQuery( $relatedQuery, $request )->get();

            if ( !$product ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product not found in tenant database',
                ] );
            }
        } else {
            $product = $this->applyWebsiteVisibleProductFilter(
                Product::with( [
                    'category',
                    'subcategory',
                    'brand',
                    'productImage',
                    'productdetails',
                    'vendor',
                    'productVariant.size',
                    'productVariant.unit',
                    'productVariant.color',
                    'productVariant.product',
                ] )->where( 'slug', $slug )
            )->first();

            if ( !$product ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product not found',
                ] );
            }

            $relatedQuery = $this->applyWebsiteVisibleProductFilter(
                Product::with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails', 'vendor' )
                    ->where( 'status', 'active' )
                    ->where( 'category_id', $product->category_id )
                    ->where( 'id', '!=', $product->id )
            );
            $related_products = $this->applyListLimitToQuery( $relatedQuery, $request )->get();
        }

        $reviewPayload = $this->buildProductReviewPayload( $product->id, $reviewConnection );

        return response()->json([
            'product' => $product,
            'related_products' => $related_products,
            'reviews' => $reviewPayload,
        ]);
    }

    public function categories()
    {
        if (tenant()->type == 'dropshipper') {
            // Step 1: Get all ProductDetails from current tenant's database
            $allProductDetails = ProductDetails::where('status', 1)->get();

            // Step 2: Collect unique category IDs from products
            $categoryIds = collect();

            foreach ( $allProductDetails as $productDetail ) {
                // Get tenant_id from ProductDetails record (this is the merchant tenant ID)
                $storedTenantId = $productDetail->tenant_id;

                if ( !$storedTenantId || !$productDetail->product_id ) {
                    continue;
                }

                // Lookup tenant from central database
                $tenant = Tenant::on( 'mysql' )->find( $storedTenantId );
                if ( !$tenant ) {
                    continue;
                }

                $connectionName = 'tenant_' . $tenant->id;
                $databaseName   = 'affsellc_' . $tenant->id;

                // Configure connection to the tenant database specified by tenant_id
                config( [
                    'database.connections.' . $connectionName => [
                        'driver'   => 'mysql',
                        'host'     => config( 'database.connections.mysql.host' ),
                        'port'     => config( 'database.connections.mysql.port' ),
                        'database' => $databaseName,
                        'username' => config( 'database.connections.mysql.username' ),
                        'password' => config( 'database.connections.mysql.password' ),
                        'charset'  => 'utf8mb4',
                        'collation'=> 'utf8mb4_unicode_ci',
                        'strict'   => false,
                    ],
                ] );
                DB::purge( $connectionName );

                // Get market_place_category_id from the product using DB facade
                $categoryId = DB::connection( $connectionName )
                    ->table( 'products' )
                    ->where( 'id', $productDetail->product_id )
                    ->where( 'is_show_website', 1 )
                    ->value( 'market_place_category_id' );

                if ( $categoryId ) {
                    $categoryIds->push( $categoryId );
                }
            }

            // Step 3: Get unique categories from m_p_categories (mysql database)
            $uniqueCategoryIds = $categoryIds->filter()->unique()->values()->toArray();

            if ( !empty( $uniqueCategoryIds ) ) {
                // Query categories from mysql database using DB facade
                $categoryData = DB::connection( 'mysql' )
                    ->table( 'm_p_categories' )
                    ->whereIn( 'id', $uniqueCategoryIds )
                    ->whereNull( 'deleted_at' )
                    ->get();

                // Convert to MPCategory model instances
                $categories = $categoryData->map( function( $item ) {
                    $category = new MPCategory();
                    $category->setConnection('mysql');
                    $category->setRawAttributes( (array) $item );
                    $category->exists = true;
                    return $category;
                } );
            } else {
                $categories = collect();
            }
        } else {
            $categories = Category::where('status', 'active')->get();
        }

        return response()->json($categories);
    }
    public function colors()
    {
        $colors = Color::where('status', 'active')->get();

        return response()->json($colors);
    }
    public function size()
    {
        $sizes = Size::where('status', 'active')->get();

        return response()->json($sizes);
    }
    public function subcategories()
    {
        if (tenant()->type == 'dropshipper') {
            // Step 1: Get all ProductDetails from current tenant's database
            $allProductDetails = ProductDetails::where('status', 1)->get();

            // Step 2: Collect unique subcategory IDs from products
            $subcategoryIds = collect();

            foreach ( $allProductDetails as $productDetail ) {
                // Get tenant_id from ProductDetails record (this is the merchant tenant ID)
                $storedTenantId = $productDetail->tenant_id;

                if ( !$storedTenantId || !$productDetail->product_id ) {
                    continue;
                }

                // Lookup tenant from central database
                $tenant = Tenant::on( 'mysql' )->find( $storedTenantId );
                if ( !$tenant ) {
                    continue;
                }

                $connectionName = 'tenant_' . $tenant->id;
                $databaseName   = 'affsellc_' . $tenant->id;

                // Configure connection to the tenant database specified by tenant_id
                config( [
                    'database.connections.' . $connectionName => [
                        'driver'   => 'mysql',
                        'host'     => config( 'database.connections.mysql.host' ),
                        'port'     => config( 'database.connections.mysql.port' ),
                        'database' => $databaseName,
                        'username' => config( 'database.connections.mysql.username' ),
                        'password' => config( 'database.connections.mysql.password' ),
                        'charset'  => 'utf8mb4',
                        'collation'=> 'utf8mb4_unicode_ci',
                        'strict'   => false,
                    ],
                ] );
                DB::purge( $connectionName );

                // Get market_place_subcategory_id from the product using DB facade
                $subcategoryId = DB::connection( $connectionName )
                    ->table( 'products' )
                    ->where( 'id', $productDetail->product_id )
                    ->where( 'is_show_website', 1 )
                    ->value( 'market_place_subcategory_id' );

                if ( $subcategoryId ) {
                    $subcategoryIds->push( $subcategoryId );
                }
            }

            // Step 3: Get unique subcategories from m_p_sub_categories (mysql database)
            $uniqueSubcategoryIds = $subcategoryIds->filter()->unique()->values()->toArray();

            if ( !empty( $uniqueSubcategoryIds ) ) {
                // Query subcategories from mysql database using DB facade
                $subcategoryData = DB::connection( 'mysql' )
                    ->table( 'm_p_sub_categories' )
                    ->whereIn( 'id', $uniqueSubcategoryIds )
                    ->whereNull( 'deleted_at' )
                    ->get();

                // Convert to MPSubCategory model instances
                $subcategories = $subcategoryData->map( function( $item ) {
                    $subcategory = new MPSubCategory();
                    $subcategory->setConnection('mysql');
                    $subcategory->setRawAttributes( (array) $item );
                    $subcategory->exists = true;
                    return $subcategory;
                } );
            } else {
                $subcategories = collect();
            }
        } else {
            $subcategories = Subcategory::all();
        }

        return response()->json($subcategories);
    }
    public function brands()
    {
        if (tenant()->type == 'dropshipper') {
            // Step 1: Get all ProductDetails from current tenant's database
            $allProductDetails = ProductDetails::where('status', 1)->get();

            // Step 2: Collect unique brand IDs from products
            $brandIds = collect();

            foreach ( $allProductDetails as $productDetail ) {
                // Get tenant_id from ProductDetails record (this is the merchant tenant ID)
                $storedTenantId = $productDetail->tenant_id;

                if ( !$storedTenantId || !$productDetail->product_id ) {
                    continue;
                }

                // Lookup tenant from central database
                $tenant = Tenant::on( 'mysql' )->find( $storedTenantId );
                if ( !$tenant ) {
                    continue;
                }

                $connectionName = 'tenant_' . $tenant->id;
                $databaseName   = 'affsellc_' . $tenant->id;

                // Configure connection to the tenant database specified by tenant_id
                config( [
                    'database.connections.' . $connectionName => [
                        'driver'   => 'mysql',
                        'host'     => config( 'database.connections.mysql.host' ),
                        'port'     => config( 'database.connections.mysql.port' ),
                        'database' => $databaseName,
                        'username' => config( 'database.connections.mysql.username' ),
                        'password' => config( 'database.connections.mysql.password' ),
                        'charset'  => 'utf8mb4',
                        'collation'=> 'utf8mb4_unicode_ci',
                        'strict'   => false,
                    ],
                ] );
                DB::purge( $connectionName );

                // Get market_place_brand_id from the product using DB facade
                $brandId = DB::connection( $connectionName )
                    ->table( 'products' )
                    ->where( 'id', $productDetail->product_id )
                    ->where( 'is_show_website', 1 )
                    ->value( 'market_place_brand_id' );

                if ( $brandId ) {
                    $brandIds->push( $brandId );
                }
            }

            // Step 3: Get unique brands from m_p_brands (mysql database)
            $uniqueBrandIds = $brandIds->filter()->unique()->values()->toArray();

            if ( !empty( $uniqueBrandIds ) ) {
                // Query brands from mysql database using DB facade
                $brandData = DB::connection( 'mysql' )
                    ->table( 'm_p_brands' )
                    ->whereIn( 'id', $uniqueBrandIds )
                    ->whereNull( 'deleted_at' )
                    ->get();

                // Convert to MPBrand model instances
                $brands = $brandData->map( function( $item ) {
                    $brand = new MPBrand();
                    $brand->setConnection('mysql');
                    $brand->setRawAttributes( (array) $item );
                    $brand->exists = true;
                    return $brand;
                } );
            } else {
                $brands = collect();
            }
        } else {
            $brands = Brand::all();
        }

        return response()->json($brands);
    }

    public function cmsFront()
    {
        $contentServices = ServiceContent::orderBy('order', 'asc')->get();
        $banners = Banner::orderBy('order', 'asc')->get();
        $offers = Offer::latest()->get();
        $cms = CmsSetting::first();
        $package_info = UserSubscription::on('mysql')->where('tenant_id', tenant()->id)->first();

        $website_visits = $package_info->website_visits;
        $already_visits = $package_info->already_visits;
        $has_website = $package_info->has_website;
        // Use null-safe operator to prevent errors when $cms is null
        $populer_section_category_id_1 = $cms ? Category::find($cms->populer_section_category_id_1) : null;
        $populer_section_category_id_2 = $cms ? Category::find($cms->populer_section_category_id_2) : null;
        $populer_section_category_id_3 = $cms ? Category::find($cms->populer_section_category_id_3) : null;
        $populer_section_category_id_4 = $cms ? Category::find($cms->populer_section_category_id_4) : null;
        $populer_section_subcategory_id_1 = $cms ? Subcategory::find($cms->populer_section_subcategory_id_1) : null;
        $populer_section_subcategory_id_2 = $cms ? Subcategory::find($cms->populer_section_subcategory_id_2) : null;
        $populer_section_subcategory_id_3 = $cms ? Subcategory::find($cms->populer_section_subcategory_id_3) : null;
        $populer_section_subcategory_id_4 = $cms ? Subcategory::find($cms->populer_section_subcategory_id_4) : null;

        $recomended_category_id_1 = $cms ? Category::find($cms->recomended_category_id_1) : null;
        $recomended_category_id_2 = $cms ? Category::find($cms->recomended_category_id_2) : null;
        $recomended_category_id_3 = $cms ? Category::find($cms->recomended_category_id_3) : null;
        $recomended_category_id_4 = $cms ? Category::find($cms->recomended_category_id_4) : null;
        $recomended_sub_category_id_1 = $cms ? Subcategory::find($cms->recomended_sub_category_id_1) : null;
        $recomended_sub_category_id_2 = $cms ? Subcategory::find($cms->recomended_sub_category_id_2) : null;
        $recomended_sub_category_id_3 = $cms ? Subcategory::find($cms->recomended_sub_category_id_3) : null;
        $recomended_sub_category_id_4 = $cms ? Subcategory::find($cms->recomended_sub_category_id_4) : null;

        $best_setting_category_id_1 = $cms ? Category::find($cms->best_setting_category_id_1) : null;
        $best_setting_category_id_2 = $cms ? Category::find($cms->best_setting_category_id_2) : null;
        $best_setting_category_id_3 = $cms ? Category::find($cms->best_setting_category_id_3) : null;
        $best_setting_category_id_4 = $cms ? Category::find($cms->best_setting_category_id_4) : null;
        $best_setting_sub_category_id_1 = $cms ? Subcategory::find($cms->best_setting_sub_category_id_1) : null;
        $best_setting_sub_category_id_2 = $cms ? Subcategory::find($cms->best_setting_sub_category_id_2) : null;
        $best_setting_sub_category_id_3 = $cms ? Subcategory::find($cms->best_setting_sub_category_id_3) : null;
        $best_setting_sub_category_id_4 = $cms ? Subcategory::find($cms->best_setting_sub_category_id_4) : null;
        $best_category_id = $cms ? Category::find($cms->best_category_id) : null;
        $best_sub_category_id = $cms ? Subcategory::find($cms->best_sub_category_id) : null;
        return response()->json([
            'content_services' => $contentServices,
            'banners' => $banners,
            'cms' => $cms,
            'offers' => $offers,
            'populer_section_category_id_1' => $populer_section_category_id_1,
            'populer_section_category_id_2' => $populer_section_category_id_2,
            'populer_section_category_id_3' => $populer_section_category_id_3,
            'populer_section_category_id_4' => $populer_section_category_id_4,
            'populer_section_subcategory_id_1' => $populer_section_subcategory_id_1,
            'populer_section_subcategory_id_2' => $populer_section_subcategory_id_2,
            'populer_section_subcategory_id_3' => $populer_section_subcategory_id_3,
            'populer_section_subcategory_id_4' => $populer_section_subcategory_id_4,
            'recomended_category_id_1' => $recomended_category_id_1,
            'recomended_category_id_2' => $recomended_category_id_2,
            'recomended_category_id_3' => $recomended_category_id_3,
            'recomended_category_id_4' => $recomended_category_id_4,
            'recomended_sub_category_id_1' => $recomended_sub_category_id_1,
            'recomended_sub_category_id_2' => $recomended_sub_category_id_2,
            'recomended_sub_category_id_3' => $recomended_sub_category_id_3,
            'recomended_sub_category_id_4' => $recomended_sub_category_id_4,
            'best_setting_category_id_1' => $best_setting_category_id_1,
            'best_setting_category_id_2' => $best_setting_category_id_2,
            'best_setting_category_id_3' => $best_setting_category_id_3,
            'best_setting_category_id_4' => $best_setting_category_id_4,
            'best_setting_sub_category_id_1' => $best_setting_sub_category_id_1,
            'best_setting_sub_category_id_2' => $best_setting_sub_category_id_2,
            'best_setting_sub_category_id_3' => $best_setting_sub_category_id_3,
            'best_setting_sub_category_id_4' => $best_setting_sub_category_id_4,
            'best_category_id' => $best_category_id,
            'best_sub_category_id' => $best_sub_category_id,
            'website_visits' => $website_visits,
            'already_visits' => $already_visits,
            'has_website' => $has_website,
        ]);
    }

    public function productsFilter( Request $request, $sub_category_id ) {
        $products = collect();

        if ( $sub_category_id != null ) {
            $query = $this->applyWebsiteVisibleProductFilter(
                Product::where( 'status', 'active' )
                    ->where( 'subcategory_id', $sub_category_id )
                    ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails' )
            );

            $listLimit = $this->requestListLimit( $request );
            if ( $listLimit !== null ) {
                $products = $this->applyListLimitToQuery( $query, $request )->get();
            } else {
                $products = $query->get();
                if ( $request->filled( 'page' ) || $request->filled( 'per_page' ) ) {
                    return response()->json( $this->paginateProductCollection( $request, $products ) );
                }
            }
        }

        return response()->json( $products );
    }

    public function searchItem( Request $request, $search, $category_id = null ) {
        // category_id from route (search/item/term/2) or query string (?category_id=2)
        $category_id = $category_id ?? $request->query( 'category_id' );

        $query = $this->applyWebsiteVisibleProductFilter(
            Product::where( 'status', 'active' )
                ->where( 'name', 'like', '%' . $search . '%' )
                ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails' )
        );

        if ( $category_id !== null && $category_id !== '' && (int) $category_id > 0 ) {
            $query->where( 'category_id', (int) $category_id );
        }

        $listLimit = $this->requestListLimit( $request );
        if ( $listLimit !== null ) {
            return response()->json( $this->applyListLimitToQuery( $query, $request )->get() );
        }

        $products = $query->get();
        if ( $request->filled( 'page' ) || $request->filled( 'per_page' ) ) {
            return response()->json( $this->paginateProductCollection( $request, $products ) );
        }

        return response()->json( $products );
    }

    public function orders() {
        $orders = Order::where('user_id', auth()->id())->where('tenant_id', tenant()->id)
            ->with( 'product:id,name,slug,image', 'productrating' )
            ->get();

        $reviewedProductIds = ProductRating::where( 'user_id', auth()->id() )->pluck( 'product_id' );
        $eligibleStatuses   = \App\Http\Requests\TenantProductReviewRequest::PURCHASE_STATUSES;

        $orders = $orders->map( function ( $order ) use ( $reviewedProductIds, $eligibleStatuses ) {
            $order->variants = Order::normalizeVariants( $order->variants );

            $order->can_review = in_array( $order->status, $eligibleStatuses, true )
                && !$reviewedProductIds->contains( $order->product_id )
                && !$order->productrating;

            if ( $order->productrating ) {
                $order->review_status = $order->productrating->is_visible ? 'approved' : 'pending';
            }

            return $order;
        } );

        $all_order = $orders->count();
        $pending_order = $orders->where('status', 'pending')->count();
        $processing_order = $orders->where('status', 'processing')->count();
        $completed_order = $orders->where('status', 'completed')->count();
        $cancelled_order = $orders->where('status', 'cancelled')->count();
        return response()->json([
            'orders' => $orders,
            'all_order' => $all_order,
            'pending_order' => $pending_order,
            'processing_order' => $processing_order,
            'completed_order' => $completed_order,
            'cancelled_order' => $cancelled_order
        ]);
    }

    public function contact(Request $request) {
        $contact = new TenantContactFormData();
        $contact->name = $request->name;
        $contact->email = $request->email;
        $contact->subject = $request->subject;
        $contact->message = $request->message;
        $contact->save();
        return response()->json($contact);
    }
    public function newsFront()
    {
        $news = News::with('nCategory')->get();
        return response()->json([
            'status' => 200,
            'news' => $news,
        ]);
    }
    public function newsDetail($slug)
    {
        $news = News::with('nCategory')->where('slug', $slug)->first();
        return response()->json([
            'status' => 200,
            'news' => $news,
        ]);
    }
    public function newsCategory()
    {
        $categories = NCategory::all();
        return response()->json([
            'status' => 200,
            'categories' => $categories,
        ]);
    }
}
