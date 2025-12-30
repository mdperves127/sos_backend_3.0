<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductDetails;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use App\Services\CrossTenantQueryService;

class ProductStatusController extends Controller
{

    public function AdminRequestPending()
    {
        // if(checkpermission('pending-request') != 1){
        //     return $this->permissionmessage();
        // }
        $search = request('search');

        // Step 1: Get all dropshipper tenants
        $dropshipperTenants = Tenant::on('mysql')->where('type', 'dropshipper')->get();

        // Step 2: Query ProductDetails from ALL dropshipper tenant databases with status = 2 (pending)
        $allProductDetails = collect();

        foreach ( $dropshipperTenants as $dropshipperTenant ) {
            try {
                $connectionName = 'tenant_' . $dropshipperTenant->id;
                $databaseName = 'sosanik_tenant_' . $dropshipperTenant->id;

                // Configure dropshipper tenant connection
                config([
                    'database.connections.' . $connectionName => [
                        'driver' => 'mysql',
                        'host' => config('database.connections.mysql.host'),
                        'port' => config('database.connections.mysql.port'),
                        'database' => $databaseName,
                        'username' => config('database.connections.mysql.username'),
                        'password' => config('database.connections.mysql.password'),
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'strict' => false,
                    ]
                ]);
                DB::purge( $connectionName );

                // Build query for this dropshipper tenant's database
                $query = DB::connection( $connectionName )->table( 'product_details' )
                    ->where('status', '2');

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'product_details.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'products.name', 'like', "%{$search}%" )
                                ->orWhere( 'product_details.uniqid', 'like', "%{$search}%" );
                          } )
                          ->select(
                              'product_details.*',
                              DB::raw( 'product_details.tenant_id as merchant_tenant_id' )
                          )
                          ->groupBy( 'product_details.id' );
                } else {
                    $query->select(
                        'product_details.*',
                        DB::raw( 'product_details.tenant_id as merchant_tenant_id' )
                    );
                }

                // Order by latest
                $query->orderBy( 'product_details.created_at', 'desc' );

                // Execute query for this dropshipper tenant
                $tenantResults = $query->get();

                // Add dropshipper tenant context to each result
                $tenantResults->transform( function ( $item ) use ( $dropshipperTenant ) {
                    $item->dropshipper_tenant_id = $dropshipperTenant->id;
                    $item->dropshipper_tenant_name = $dropshipperTenant->company_name;
                    return $item;
                } );

                $allProductDetails = $allProductDetails->merge( $tenantResults );
            } catch ( \Exception $e ) {
                \Log::warning( "Failed to query dropshipper tenant {$dropshipperTenant->id}: " . $e->getMessage() );
                continue;
            } finally {
                // Reconnect to central database
                DB::setDefaultConnection( 'mysql' );
            }
        }

        // Step 3: For each ProductDetails, load product from merchant tenant database (using tenant_id from product_details)
        $productDetails = collect( $allProductDetails )->map( function ( $productDetail ) {
            // The tenant_id in product_details points to the merchant tenant where the product exists
            $merchantTenantId = $productDetail->merchant_tenant_id ?? $productDetail->tenant_id ?? null;
            $dropshipperTenantId = $productDetail->dropshipper_tenant_id;

            if ( !$merchantTenantId || !isset( $productDetail->product_id ) ) {
                return $productDetail;
            }

            // Get merchant tenant
            $merchantTenant = Tenant::on('mysql')->find( $merchantTenantId );
            if ( !$merchantTenant ) {
                return $productDetail;
            }

            $merchantConnectionName = 'tenant_' . $merchantTenant->id;
            $merchantDatabaseName = 'sosanik_tenant_' . $merchantTenant->id;

            // Configure merchant tenant connection
            config([
                'database.connections.' . $merchantConnectionName => [
                    'driver' => 'mysql',
                    'host' => config('database.connections.mysql.host'),
                    'port' => config('database.connections.mysql.port'),
                    'database' => $merchantDatabaseName,
                    'username' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict' => false,
                ]
            ]);
            DB::purge( $merchantConnectionName );

            // Load product from merchant tenant database
            if ( isset( $productDetail->product_id ) ) {
                $product = Product::on( $merchantConnectionName )
                    ->select( 'id', 'name', 'image', 'discount_rate' )
                    ->find( $productDetail->product_id );
                $productDetail->product = $product;
            }

            // Load vendor from merchant tenant database
            if ( isset( $productDetail->vendor_id ) ) {
                $vendor = User::on( $merchantConnectionName )->select( 'id', 'name' )->find( $productDetail->vendor_id );
                $productDetail->vendor = $vendor;
            }

            // Load affiliator (dropshipper tenant)
            if ( $dropshipperTenantId ) {
                $affiliator = Tenant::on('mysql')->select('id', 'company_name as name')->find( $dropshipperTenantId );
                $productDetail->affiliator = $affiliator;
            }

            return $productDetail;
        } )->values();

        // Manual pagination
        $page = request()->get('page', 1);
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $paginatedProductDetails = $productDetails->slice($offset, $perPage);

        return response()->json([
            'status' => 200,
            'product' => [
                'data' => $paginatedProductDetails->values(),
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $productDetails->count(),
                'last_page' => ceil($productDetails->count() / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $productDetails->count()),
            ],
        ]);
    }


    public function AdminRequestActive()
    {
        // if(checkpermission('active-request') != 1){
        //     return $this->permissionmessage();
        // }

        $search = request('search');

        // Step 1: Get all dropshipper tenants
        $dropshipperTenants = Tenant::on('mysql')->where('type', 'dropshipper')->get();

        // Step 2: Query ProductDetails from ALL dropshipper tenant databases with status = 1
        $allProductDetails = collect();

        foreach ( $dropshipperTenants as $dropshipperTenant ) {
            try {
                $connectionName = 'tenant_' . $dropshipperTenant->id;
                $databaseName = 'sosanik_tenant_' . $dropshipperTenant->id;

                // Configure dropshipper tenant connection
                config([
                    'database.connections.' . $connectionName => [
                        'driver' => 'mysql',
                        'host' => config('database.connections.mysql.host'),
                        'port' => config('database.connections.mysql.port'),
                        'database' => $databaseName,
                        'username' => config('database.connections.mysql.username'),
                        'password' => config('database.connections.mysql.password'),
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'strict' => false,
                    ]
                ]);
                DB::purge( $connectionName );

                // Build query for this dropshipper tenant's database
                $query = DB::connection( $connectionName )->table( 'product_details' )
                    ->where('status', '1');

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'product_details.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'products.name', 'like', "%{$search}%" )
                                ->orWhere( 'product_details.uniqid', 'like', "%{$search}%" );
                          } )
                          ->select(
                              'product_details.*',
                              DB::raw( 'product_details.tenant_id as merchant_tenant_id' )
                          )
                          ->groupBy( 'product_details.id' );
                } else {
                    $query->select(
                        'product_details.*',
                        DB::raw( 'product_details.tenant_id as merchant_tenant_id' )
                    );
                }

                // Order by latest
                $query->orderBy( 'product_details.created_at', 'desc' );

                // Execute query for this dropshipper tenant
                $tenantResults = $query->get();

                // Add dropshipper tenant context to each result
                $tenantResults->transform( function ( $item ) use ( $dropshipperTenant ) {
                    $item->dropshipper_tenant_id = $dropshipperTenant->id;
                    $item->dropshipper_tenant_name = $dropshipperTenant->company_name;
                    return $item;
                } );

                $allProductDetails = $allProductDetails->merge( $tenantResults );
            } catch ( \Exception $e ) {
                \Log::warning( "Failed to query dropshipper tenant {$dropshipperTenant->id}: " . $e->getMessage() );
                continue;
            } finally {
                // Reconnect to central database
                DB::setDefaultConnection( 'mysql' );
            }
        }

        // Step 3: For each ProductDetails, load product from merchant tenant database (using tenant_id from product_details)
        $productDetails = collect( $allProductDetails )->map( function ( $productDetail ) {
            // The tenant_id in product_details points to the merchant tenant where the product exists
            $merchantTenantId = $productDetail->merchant_tenant_id ?? $productDetail->tenant_id ?? null;
            $dropshipperTenantId = $productDetail->dropshipper_tenant_id;

            if ( !$merchantTenantId || !isset( $productDetail->product_id ) ) {
                return $productDetail;
            }

            // Get merchant tenant
            $merchantTenant = Tenant::on('mysql')->find( $merchantTenantId );
            if ( !$merchantTenant ) {
                return $productDetail;
            }

            $merchantConnectionName = 'tenant_' . $merchantTenant->id;
            $merchantDatabaseName = 'sosanik_tenant_' . $merchantTenant->id;

            // Configure merchant tenant connection
            config([
                'database.connections.' . $merchantConnectionName => [
                    'driver' => 'mysql',
                    'host' => config('database.connections.mysql.host'),
                    'port' => config('database.connections.mysql.port'),
                    'database' => $merchantDatabaseName,
                    'username' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict' => false,
                ]
            ]);
            DB::purge( $merchantConnectionName );

            // Load product from merchant tenant database
            if ( isset( $productDetail->product_id ) ) {
                $product = Product::on( $merchantConnectionName )
                    ->select( 'id', 'name', 'image', 'discount_rate' )
                    ->find( $productDetail->product_id );
                $productDetail->product = $product;
            }

            // Load vendor from merchant tenant database
            if ( isset( $productDetail->vendor_id ) ) {
                $vendor = User::on( $merchantConnectionName )->select( 'id', 'name' )->find( $productDetail->vendor_id );
                $productDetail->vendor = $vendor;
            }

            // Load affiliator (dropshipper tenant)
            if ( $dropshipperTenantId ) {
                $affiliator = Tenant::on('mysql')->select('id', 'company_name as name')->find( $dropshipperTenantId );
                $productDetail->affiliator = $affiliator;
            }

            return $productDetail;
        } )->values();

        // Manual pagination
        $page = request()->get('page', 1);
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $paginatedProductDetails = $productDetails->slice($offset, $perPage);

        return response()->json([
            'status' => 200,
            'product' => [
                'data' => $paginatedProductDetails->values(),
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $productDetails->count(),
                'last_page' => ceil($productDetails->count() / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $productDetails->count()),
            ],
        ]);
    }


    function AdminRequestAll()
    {
        // if(checkpermission('all-request') != 1){
        //     return $this->permissionmessage();
        // }

        $search = request('search');

        // Query ProductDetails from all merchant tenant databases
        $allProductDetails = CrossTenantQueryService::queryAllTenants(
            ProductDetails::class,
            function ( $query ) use ( $search ) {
                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'product_details.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'products.name', 'like', "%{$search}%" )
                                ->orWhere( 'products.uniqid', 'like', "%{$search}%" );
                          } )
                          ->select( 'product_details.*' )
                          ->groupBy( 'product_details.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $productDetails = collect( $allProductDetails )->map( function ( $productDetail ) {
            // Load relationships manually for each product detail
            if ( isset( $productDetail->product_id ) && isset( $productDetail->tenant_id ) ) {
                $tenant = Tenant::find( $productDetail->tenant_id );
                if ( $tenant ) {
                    $connectionName = 'tenant_' . $tenant->id;
                    $databaseName = 'sosanik_tenant_' . $tenant->id;

                    // Configure connection using the same method as CrossTenantQueryService
                    config([
                        'database.connections.' . $connectionName => [
                            'driver' => 'mysql',
                            'host' => config('database.connections.mysql.host'),
                            'port' => config('database.connections.mysql.port'),
                            'database' => $databaseName,
                            'username' => config('database.connections.mysql.username'),
                            'password' => config('database.connections.mysql.password'),
                            'charset' => 'utf8mb4',
                            'collation' => 'utf8mb4_unicode_ci',
                            'strict' => false,
                        ]
                    ]);
                    DB::purge( $connectionName );

                    // Load product
                    $product = Product::on( $connectionName )->select( 'id', 'name', 'image', 'discount_rate' )->find( $productDetail->product_id );
                    $productDetail->product = $product;

                    // Load vendor
                    if ( isset( $productDetail->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $productDetail->vendor_id );
                        $productDetail->vendor = $vendor;
                    }

                    // Load affiliator (from Tenant table where type = dropshipper)
                    if ( isset( $productDetail->tenant_id ) ) {
                        $affiliator = Tenant::on('mysql')->select('id', 'company_name as name')->find( $productDetail->tenant_id );
                        $productDetail->affiliator = $affiliator;
                    }
                }
            }

            return $productDetail;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $productDetails = $productDetails->sortByDesc( function ( $productDetail ) {
            return $productDetail->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedProductDetails = $productDetails->slice( $offset, $perPage );
        $lastPage = ceil( $productDetails->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedProductDetails->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $productDetails->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $productDetails->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $buildUrl( $lastPage ),
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json([
            'status' => 200,
            'product' => $response,
        ]);

    }


    function RequestRejected()
    {
        // if(checkpermission('rejected-request') != 1){
        //     return $this->permissionmessage();
        // }
        $search = request('search');

        // Step 1: Get all dropshipper tenants
        $dropshipperTenants = Tenant::on('mysql')->where('type', 'dropshipper')->get();

        // Step 2: Query ProductDetails from ALL dropshipper tenant databases with status = 3 (rejected)
        $allProductDetails = collect();

        foreach ( $dropshipperTenants as $dropshipperTenant ) {
            try {
                $connectionName = 'tenant_' . $dropshipperTenant->id;
                $databaseName = 'sosanik_tenant_' . $dropshipperTenant->id;

                // Configure dropshipper tenant connection
                config([
                    'database.connections.' . $connectionName => [
                        'driver' => 'mysql',
                        'host' => config('database.connections.mysql.host'),
                        'port' => config('database.connections.mysql.port'),
                        'database' => $databaseName,
                        'username' => config('database.connections.mysql.username'),
                        'password' => config('database.connections.mysql.password'),
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'strict' => false,
                    ]
                ]);
                DB::purge( $connectionName );

                // Build query for this dropshipper tenant's database
                $query = DB::connection( $connectionName )->table( 'product_details' )
                    ->where('status', '3');

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'product_details.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'products.name', 'like', "%{$search}%" )
                                ->orWhere( 'product_details.uniqid', 'like', "%{$search}%" );
                          } )
                          ->select(
                              'product_details.*',
                              DB::raw( 'product_details.tenant_id as merchant_tenant_id' )
                          )
                          ->groupBy( 'product_details.id' );
                } else {
                    $query->select(
                        'product_details.*',
                        DB::raw( 'product_details.tenant_id as merchant_tenant_id' )
                    );
                }

                // Order by latest
                $query->orderBy( 'product_details.created_at', 'desc' );

                // Execute query for this dropshipper tenant
                $tenantResults = $query->get();

                // Add dropshipper tenant context to each result
                $tenantResults->transform( function ( $item ) use ( $dropshipperTenant ) {
                    $item->dropshipper_tenant_id = $dropshipperTenant->id;
                    $item->dropshipper_tenant_name = $dropshipperTenant->company_name;
                    return $item;
                } );

                $allProductDetails = $allProductDetails->merge( $tenantResults );
            } catch ( \Exception $e ) {
                \Log::warning( "Failed to query dropshipper tenant {$dropshipperTenant->id}: " . $e->getMessage() );
                continue;
            } finally {
                // Reconnect to central database
                DB::setDefaultConnection( 'mysql' );
            }
        }

        // Step 3: For each ProductDetails, load product from merchant tenant database (using tenant_id from product_details)
        $productDetails = collect( $allProductDetails )->map( function ( $productDetail ) {
            // The tenant_id in product_details points to the merchant tenant where the product exists
            $merchantTenantId = $productDetail->merchant_tenant_id ?? $productDetail->tenant_id ?? null;
            $dropshipperTenantId = $productDetail->dropshipper_tenant_id;

            if ( !$merchantTenantId || !isset( $productDetail->product_id ) ) {
                return $productDetail;
            }

            // Get merchant tenant
            $merchantTenant = Tenant::on('mysql')->find( $merchantTenantId );
            if ( !$merchantTenant ) {
                return $productDetail;
            }

            $merchantConnectionName = 'tenant_' . $merchantTenant->id;
            $merchantDatabaseName = 'sosanik_tenant_' . $merchantTenant->id;

            // Configure merchant tenant connection
            config([
                'database.connections.' . $merchantConnectionName => [
                    'driver' => 'mysql',
                    'host' => config('database.connections.mysql.host'),
                    'port' => config('database.connections.mysql.port'),
                    'database' => $merchantDatabaseName,
                    'username' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict' => false,
                ]
            ]);
            DB::purge( $merchantConnectionName );

            // Load product from merchant tenant database
            if ( isset( $productDetail->product_id ) ) {
                $product = Product::on( $merchantConnectionName )
                    ->select( 'id', 'name', 'image', 'discount_rate' )
                    ->find( $productDetail->product_id );
                $productDetail->product = $product;
            }

            // Load vendor from merchant tenant database
            if ( isset( $productDetail->vendor_id ) ) {
                $vendor = User::on( $merchantConnectionName )->select( 'id', 'name' )->find( $productDetail->vendor_id );
                $productDetail->vendor = $vendor;
            }

            // Load affiliator (dropshipper tenant)
            if ( $dropshipperTenantId ) {
                $affiliator = Tenant::on('mysql')->select('id', 'company_name as name')->find( $dropshipperTenantId );
                $productDetail->affiliator = $affiliator;
            }

            return $productDetail;
        } )->values();

        // Manual pagination
        $page = request()->get('page', 1);
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $paginatedProductDetails = $productDetails->slice($offset, $perPage);

        return response()->json([
            'status' => 200,
            'product' => [
                'data' => $paginatedProductDetails->values(),
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $productDetails->count(),
                'last_page' => ceil($productDetails->count() / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $productDetails->count()),
            ],
        ]);
    }


    function RequestUpdate(Request $request, $tenant_id, $id)
    {
        // Get tenant from request
        $tenant = Tenant::where('id',$tenant_id)->first();
        if (!$tenant) {
            return response()->json([
                'status' => 404,
                'message' => 'Tenant not found',
            ]);
        }

        // Get ProductDetails from tenant's database
        $data = CrossTenantQueryService::getSingleFromTenant(
            $tenant_id,
            ProductDetails::class,
            function ($query) use ($id) {
                $query->where('id', $id);
            }
        );

        if (!$data) {
            return response()->json([
                'status' => 404,
                'message' => 'ProductDetails not found',
            ]);
        }

        // Remove tenant context attributes that were added by getSingleFromTenant
        // These are not actual database columns and will cause errors if saved
        unset($data->tenant_id);
        unset($data->tenant_domain);
        unset($data->tenant_name);

        // Update the ProductDetails
        $data->status = $request->status;
        $data->reason = $request->reason;
        $data->save();
        return response()->json([
            'status' => 200,
            'message' => 'updated successfully',
        ]);
    }


    function AdminRequestView($id)
    {
        $product = ProductDetails::with(['vendor', 'affiliator', 'product' => function ($query) {
            $query->with('productImage');
        }])->find($id);



        return response()->json([
            'status' => 200,
            'product' => $product,
        ]);
    }


    public function AdminRequestBalances()
    {
        $user = User::where('balance_status', 0)->get();
        return response()->json($user);
    }


    public function AdminRequestBalanceActive()
    {
        $user = User::where('balance_status', 1)->get();
        return response()->json($user);
    }
}
