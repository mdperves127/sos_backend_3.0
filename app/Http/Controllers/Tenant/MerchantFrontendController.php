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

class MerchantFrontendController extends Controller
{
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
                $databaseName   = 'sosanik_tenant_' . $tenant->id;

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
                $productQuery = Product::on( $connectionName )
                    ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails', 'vendor' );

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

                // Apply color filter (only if color_product table exists)
                if ( $colorId ) {
                    try {
                        // Check if color_product table exists
                        $tableExists = DB::connection( $connectionName )
                            ->select( "SHOW TABLES LIKE 'color_product'" );
                        if ( !empty( $tableExists ) ) {
                            $productQuery->whereHas( 'colors', function( $q ) use ( $colorId ) {
                                $q->where( 'colors.id', $colorId );
                            } );
                        }
                    } catch ( \Exception $e ) {
                        // Table doesn't exist or error, skip color filter
                    }
                }

                // Apply size filter (only if product_size table exists)
                if ( $sizeId ) {
                    try {
                        // Check if product_size table exists (or size_product depending on pivot table name)
                        $tableExists = DB::connection( $connectionName )
                            ->select( "SHOW TABLES LIKE 'product_size'" );
                        if ( empty( $tableExists ) ) {
                            // Try alternative table name
                            $tableExists = DB::connection( $connectionName )
                                ->select( "SHOW TABLES LIKE 'size_product'" );
                        }
                        if ( !empty( $tableExists ) ) {
                            $productQuery->whereHas( 'sizes', function( $q ) use ( $sizeId ) {
                                $q->where( 'sizes.id', $sizeId );
                            } );
                        }
                    } catch ( \Exception $e ) {
                        // Table doesn't exist or error, skip size filter
                    }
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
            $productQuery = Product::where('status', 'active')
                ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails' );

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

            // Apply color filter (only if color_product table exists)
            if ( $colorId ) {
                try {
                    // Check if color_product table exists
                    $tableExists = DB::connection( 'tenant' )
                        ->select( "SHOW TABLES LIKE 'color_product'" );
                    if ( !empty( $tableExists ) ) {
                        $productQuery->whereHas( 'colors', function( $q ) use ( $colorId ) {
                            $q->where( 'colors.id', $colorId );
                        } );
                    }
                } catch ( \Exception $e ) {
                    // Table doesn't exist or error, skip color filter
                }
            }

            // Apply size filter (only if product_size table exists)
            if ( $sizeId ) {
                try {
                    // Check if product_size table exists (or size_product depending on pivot table name)
                    $tableExists = DB::connection( 'tenant' )
                        ->select( "SHOW TABLES LIKE 'product_size'" );
                    if ( empty( $tableExists ) ) {
                        // Try alternative table name
                        $tableExists = DB::connection( 'tenant' )
                            ->select( "SHOW TABLES LIKE 'size_product'" );
                    }
                    if ( !empty( $tableExists ) ) {
                        $productQuery->whereHas( 'sizes', function( $q ) use ( $sizeId ) {
                            $q->where( 'sizes.id', $sizeId );
                        } );
                    }
                } catch ( \Exception $e ) {
                    // Table doesn't exist or error, skip size filter
                }
            }

            $products = $productQuery->get();
        }

        // Manual pagination
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 10);
        $offset = ($page - 1) * $perPage;

        $total = $products->count();
        $paginatedProducts = $products->slice($offset, $perPage);
        $lastPage = (int) max(1, ceil($total / $perPage));

        // Build pagination URLs
        $path = $request->url();
        $queryParams = $request->query();
        $buildUrl = function ($pageNum) use ($path, $queryParams) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query($queryParams);
        };

        // Build links array
        $links = [];
        $links[] = [
            'url' => $page > 1 ? $buildUrl($page - 1) : null,
            'label' => '&laquo; Previous',
            'active' => false
        ];

        for ($i = 1; $i <= $lastPage; $i++) {
            $links[] = [
                'url' => $buildUrl($i),
                'label' => (string) $i,
                'active' => $i == $page
            ];
        }

        $links[] = [
            'url' => $page < $lastPage ? $buildUrl($page + 1) : null,
            'label' => 'Next &raquo;',
            'active' => false
        ];

        // Build pagination response
        $response = [
            'data' => $paginatedProducts->values(),
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : null,
            'to' => $total > 0 ? min($offset + $perPage, $total) : null,
            'path' => $path,
            'first_page_url' => $buildUrl(1),
            'last_page_url' => $buildUrl($lastPage),
            'prev_page_url' => $page > 1 ? $buildUrl($page - 1) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl($page + 1) : null,
            'links' => $links,
        ];

        return response()->json($response);
    }
    public function product($slug)
    {
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
            $databaseName   = 'sosanik_tenant_' . $tenant->id;

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

            // Load product from the tenant database using product_id
            $product = Product::on( $connectionName )
                ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails', 'vendor' )
                ->where('slug', $slug)->first();

            if ( !$product ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product not found in tenant database',
                ] );
            }

        } else {
            $product = Product::with('category', 'subcategory', 'brand', 'productImage', 'productdetails', 'vendor')->where('slug', $slug)->first();

            if ( !$product ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product not found',
                ] );
            }
        }

        return response()->json(compact('product'));
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
                $databaseName   = 'sosanik_tenant_' . $tenant->id;

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
                $databaseName   = 'sosanik_tenant_' . $tenant->id;

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
                $databaseName   = 'sosanik_tenant_' . $tenant->id;

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
}
