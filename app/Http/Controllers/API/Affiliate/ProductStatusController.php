<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AffiliateProductRequestNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Services\CrossTenantQueryService;

class ProductStatusController extends Controller {


    public function AffiliatorProducts() {

        // Get product IDs the affiliate has already requested (from their tenant DB)
        $requestedProductIds = ProductDetails::where('user_id', 1)->pluck('product_id')->toArray();

        // Query products across ALL vendor tenants
        $products = CrossTenantQueryService::queryAllTenants(
            Product::class,
            function ($query) use ($requestedProductIds) {
                return $query
                    ->where('status', 'active')
                    ->where('is_affiliate', '1')
                    ->whereNotIn('id', $requestedProductIds ?: [-1])
                    ->when(request('search'), fn($q, $name) => $q->where('name', 'like', "%{$name}%"))
                    ->when(request('warranty'), fn($q, $warranty) => $q->where('warranty', 'like', "%{$warranty}%"))
                    ->when(request('category_id'), function ($query) {
                        $query->where('category_id', request('category_id'));
                    })
                    ->when(request('start_stock') && request('end_stock'), function ($query) {
                        $query->whereBetween('qty', [request('start_stock'), request('end_stock')]);
                    })
                    ->when(request()->has('start_price') && request()->has('end_price'), function ($query) {
                        $query->where(function ($subQuery) {
                            $subQuery->whereBetween(DB::raw('CASE
                                WHEN discount_price IS NULL THEN selling_price
                                ELSE discount_price
                                END'), [request('start_price'), request('end_price')]);
                        });
                    })
                    ->when(request('start_commission') && request('end_commission'), function ($query) {
                        $query->whereBetween('discount_rate', [request('start_commission'), request('end_commission')]);
                    });
            }
        );

        // Apply additional filters and sorting
        $filteredProducts = $products->when(request('high_to_low'), function ($collection) {
            return $collection->sortByDesc(function ($product) {
                return $product->discount_price ?: $product->selling_price;
            });
        })->when(request('low_to_high'), function ($collection) {
            return $collection->sortBy(function ($product) {
                return $product->discount_price ?: $product->selling_price;
            });
        });

        // Manual pagination
        $perPage = 10;
        $currentPage = request()->get('page', 1);
        $items = $filteredProducts->forPage($currentPage, $perPage)->values();

        $product = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $filteredProducts->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    public function AffiliatorProductPendingProduct() {
        $search  = request( 'search' );
        $orderId = request( 'order_id' );

        // Step 1: Get all ProductDetails where status = 2 from current tenant's database
        $query = ProductDetails::where( 'status', 2 );

        // Filter by order_id if provided
        if ( $orderId ) {
            $query->where( 'id', 'like', "%{$orderId}%" );
        }

        // Get all ProductDetails records
        $allProductDetails = $query->latest()->get();

        // Step 2: For each ProductDetails, get tenant_id and load product from that tenant's database
        $productDetailsWithProducts = collect();

        foreach ( $allProductDetails as $productDetail ) {
            // Get tenant_id from ProductDetails record
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

            // Load product from the tenant database using product_id
            // Example: if tenant_id = "two" and product_id = 1, get product id=1 from tenant "two"'s database
            $product = Product::on( $connectionName )
                ->select( 'id', 'name', 'selling_price', 'image' )
                ->find( $productDetail->product_id );

            if ( $product ) {
                $product->load( 'productImage' );
                $productDetail->product = $product;

                // Apply search filter after loading product
                if ( $search ) {
                    $matchesUniqid = stripos( $productDetail->uniqid, $search ) !== false;
                    $matchesProductName = stripos( $product->name, $search ) !== false;

                    if ( !$matchesUniqid && !$matchesProductName ) {
                        continue; // Skip this record if it doesn't match search
                    }
                }

                // Load vendor from the same tenant database
                if ( $productDetail->vendor_id ) {
                    $vendor = User::on( $connectionName )
                        ->select( 'id', 'name' )
                        ->find( $productDetail->vendor_id );
                    $productDetail->vendor = $vendor;
                }

                // Load affiliator from the same tenant database
                if ( $productDetail->user_id ) {
                    $affiliator = User::on( $connectionName )
                        ->select( 'id', 'name' )
                        ->find( $productDetail->user_id );
                    $productDetail->affiliator = $affiliator;
                }

                $productDetailsWithProducts->push( $productDetail );
            }
        }

        // Sort by latest
        $productDetailsWithProducts = $productDetailsWithProducts->sortByDesc( function ( $productDetail ) {
            return $productDetail->created_at ?? '';
        } )->values();

        // Manual pagination after processing
        $page    = (int) request()->get( 'page', 1 );
        $perPage = 10;
        $offset  = ( $page - 1 ) * $perPage;

        $paginatedProductDetails = $productDetailsWithProducts->slice( $offset, $perPage );
        $total                   = $productDetailsWithProducts->count();
        $lastPage                = (int) max( 1, ceil( $total / $perPage ) );

        // Build pagination URLs
        $path        = request()->url();
        $queryParams = request()->query();
        $buildUrl    = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        $response = [
            'data'            => $paginatedProductDetails->values(),
            'current_page'    => $page,
            'per_page'        => $perPage,
            'total'           => $total,
            'last_page'       => $lastPage,
            'from'            => $total ? $offset + 1 : null,
            'to'              => min( $offset + $perPage, $total ),
            'path'            => $path,
            'first_page_url'  => $buildUrl( 1 ),
            'last_page_url'  => $total ? $buildUrl( $lastPage ) : null,
            'prev_page_url'  => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url'  => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'pending' => $response,
        ] );
    }

    public function AffiliatorProductActiveProduct() {
        $search  = request( 'search' );
        $orderId = request( 'order_id' );

        // Step 1: Get all ProductDetails where status = 2 from current tenant's database
        $query = ProductDetails::where( 'status', 1 );

        // Filter by order_id if provided
        if ( $orderId ) {
            $query->where( 'id', 'like', "%{$orderId}%" );
        }

        // Get all ProductDetails records
        $allProductDetails = $query->latest()->get();

        // Step 2: For each ProductDetails, get tenant_id and load product from that tenant's database
        $productDetailsWithProducts = collect();

        foreach ( $allProductDetails as $productDetail ) {
            // Get tenant_id from ProductDetails record
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

            // Load product from the tenant database using product_id
            // Example: if tenant_id = "two" and product_id = 1, get product id=1 from tenant "two"'s database
            $product = Product::on( $connectionName )
                ->select( 'id', 'name', 'selling_price', 'image' )
                ->find( $productDetail->product_id );

            if ( $product ) {
                $product->load( 'productImage' );
                $productDetail->product = $product;

                // Apply search filter after loading product
                if ( $search ) {
                    $matchesUniqid = stripos( $productDetail->uniqid, $search ) !== false;
                    $matchesProductName = stripos( $product->name, $search ) !== false;

                    if ( !$matchesUniqid && !$matchesProductName ) {
                        continue; // Skip this record if it doesn't match search
                    }
                }

                // Load vendor from the same tenant database
                if ( $productDetail->vendor_id ) {
                    $vendor = User::on( $connectionName )
                        ->select( 'id', 'name' )
                        ->find( $productDetail->vendor_id );
                    $productDetail->vendor = $vendor;
                }

                // Load affiliator from the same tenant database
                if ( $productDetail->user_id ) {
                    $affiliator = User::on( $connectionName )
                        ->select( 'id', 'name' )
                        ->find( $productDetail->user_id );
                    $productDetail->affiliator = $affiliator;
                }

                $productDetailsWithProducts->push( $productDetail );
            }
        }

        // Sort by latest
        $productDetailsWithProducts = $productDetailsWithProducts->sortByDesc( function ( $productDetail ) {
            return $productDetail->created_at ?? '';
        } )->values();

        // Manual pagination after processing
        $page    = (int) request()->get( 'page', 1 );
        $perPage = 10;
        $offset  = ( $page - 1 ) * $perPage;

        $paginatedProductDetails = $productDetailsWithProducts->slice( $offset, $perPage );
        $total                   = $productDetailsWithProducts->count();
        $lastPage                = (int) max( 1, ceil( $total / $perPage ) );

        // Build pagination URLs
        $path        = request()->url();
        $queryParams = request()->query();
        $buildUrl    = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        $response = [
            'data'            => $paginatedProductDetails->values(),
            'current_page'    => $page,
            'per_page'        => $perPage,
            'total'           => $total,
            'last_page'       => $lastPage,
            'from'            => $total ? $offset + 1 : null,
            'to'              => min( $offset + $perPage, $total ),
            'path'            => $path,
            'first_page_url'  => $buildUrl( 1 ),
            'last_page_url'  => $total ? $buildUrl( $lastPage ) : null,
            'prev_page_url'  => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url'  => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'pending' => $response,
        ] );
    }

    function vendorexpireproducts() {
        $userId     = Auth::id();
        $searchTerm = request( 'search' );

        $active = ProductDetails::with( 'product' )->where( 'user_id', $userId )
            ->where( 'status', 1 )
            ->whereHas( 'product' )
            ->when( $searchTerm != '', function ( $query ) use ( $searchTerm ) {
                $query->whereHas( 'product', function ( $query ) use ( $searchTerm ) {
                    $query->where( 'name', 'like', '%' . $searchTerm . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $searchTerm . '%' );
            } )
            ->whereHas( 'vendor', function ( $query ) {

                $query->withwhereHas( 'usersubscription', function ( $query ) {

                    $query->where( function ( $query ) {
                        $query->whereHas( 'subscription', function ( $query ) {
                            $query->where( 'plan_type', 'freemium' );
                        } )
                            ->where( 'expire_date', '<', now() );
                    } )
                        ->orwhere( function ( $query ) {
                            $query->whereHas( 'subscription', function ( $query ) {
                                $query->where( 'plan_type', '!=', 'freemium' );
                            } )
                                ->where( 'expire_date', '<', now()->subMonth( 1 ) );
                        } );
                } );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status' => 200,
            'active' => $active,
        ] );
    }

    public function AffiliatorProductRejct() { $search  = request( 'search' );
        $orderId = request( 'order_id' );

        // Step 1: Get all ProductDetails where status = 2 from current tenant's database
        $query = ProductDetails::where( 'status', 3 );

        // Filter by order_id if provided
        if ( $orderId ) {
            $query->where( 'id', 'like', "%{$orderId}%" );
        }

        // Get all ProductDetails records
        $allProductDetails = $query->latest()->get();

        // Step 2: For each ProductDetails, get tenant_id and load product from that tenant's database
        $productDetailsWithProducts = collect();

        foreach ( $allProductDetails as $productDetail ) {
            // Get tenant_id from ProductDetails record
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

            // Load product from the tenant database using product_id
            // Example: if tenant_id = "two" and product_id = 1, get product id=1 from tenant "two"'s database
            $product = Product::on( $connectionName )
                ->select( 'id', 'name', 'selling_price', 'image' )
                ->find( $productDetail->product_id );

            if ( $product ) {
                $product->load( 'productImage' );
                $productDetail->product = $product;

                // Apply search filter after loading product
                if ( $search ) {
                    $matchesUniqid = stripos( $productDetail->uniqid, $search ) !== false;
                    $matchesProductName = stripos( $product->name, $search ) !== false;

                    if ( !$matchesUniqid && !$matchesProductName ) {
                        continue; // Skip this record if it doesn't match search
                    }
                }

                // Load vendor from the same tenant database
                if ( $productDetail->vendor_id ) {
                    $vendor = User::on( $connectionName )
                        ->select( 'id', 'name' )
                        ->find( $productDetail->vendor_id );
                    $productDetail->vendor = $vendor;
                }

                // Load affiliator from the same tenant database
                if ( $productDetail->user_id ) {
                    $affiliator = User::on( $connectionName )
                        ->select( 'id', 'name' )
                        ->find( $productDetail->user_id );
                    $productDetail->affiliator = $affiliator;
                }

                $productDetailsWithProducts->push( $productDetail );
            }
        }

        // Sort by latest
        $productDetailsWithProducts = $productDetailsWithProducts->sortByDesc( function ( $productDetail ) {
            return $productDetail->created_at ?? '';
        } )->values();

        // Manual pagination after processing
        $page    = (int) request()->get( 'page', 1 );
        $perPage = 10;
        $offset  = ( $page - 1 ) * $perPage;

        $paginatedProductDetails = $productDetailsWithProducts->slice( $offset, $perPage );
        $total                   = $productDetailsWithProducts->count();
        $lastPage                = (int) max( 1, ceil( $total / $perPage ) );

        // Build pagination URLs
        $path        = request()->url();
        $queryParams = request()->query();
        $buildUrl    = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        $response = [
            'data'            => $paginatedProductDetails->values(),
            'current_page'    => $page,
            'per_page'        => $perPage,
            'total'           => $total,
            'last_page'       => $lastPage,
            'from'            => $total ? $offset + 1 : null,
            'to'              => min( $offset + $perPage, $total ),
            'path'            => $path,
            'first_page_url'  => $buildUrl( 1 ),
            'last_page_url'  => $total ? $buildUrl( $lastPage ) : null,
            'prev_page_url'  => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url'  => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'pending' => $response,
        ] );
    }

    public function AffiliatorProductRequest( Request $request, $tenant_id, $id ) {

        // $getmembershipdetails = getmembershipdetails();
        $acceptableproduct    = ProductDetails::where( ['tenant_id' => tenant()->id, 'status' => 1] )->count();

        // $productecreateqty = $getmembershipdetails?->product_request;

        $totalcreatedproduct = ProductDetails::where( 'tenant_id', tenant()->id )->count();

        // if ( ismembershipexists() != 1 ) {
        //     return responsejson( 'You do not have a membership', 'fail' );
        // }

        // if ( isactivemembership() != 1 ) {
        //     return responsejson( 'Membership expired!', 'fail' );
        // }

        // if ( $productecreateqty <= $totalcreatedproduct ) {
        //     return responsejson( 'You can not send product request more then ' . $productecreateqty . '.', 'fail' );
        // }

        // if ($getmembershipdetails?->product_approve <= $acceptableproduct) {
        //     return responsejson('Vendor product accept limit over.', 'fail');
        // }





        // Get product from the specific tenant's database
        $existproduct = CrossTenantQueryService::getSingleFromTenant(
            $tenant_id,
            Product::class,
            function ( $query ) use ( $id ) {
                $query->where( 'id', $id )
                      ->where( 'status', 'active' );
            }
        );

        if ( ! $existproduct ) {
            return $this->response( 'Product not fount' );
        }

        $product             = new ProductDetails();
        $product->status     = 2;
        $product->product_id = $existproduct->id;
        $product->vendor_id  = 0;
        $product->user_id    = 0;
        $product->reason     = request( 'reason' );
        $product->tenant_id  = $tenant_id;
        $product->uniqid     = uniqid();
        $product->save();

        // $user = User::where( 'id', $product->vendor_id )->first();
        // Notification::send( $user, new AffiliateProductRequestNotification( $user, $product ) );
        return response()->json( [
            'status'  => 200,
            'message' => 'Product Request Successfully Please Wait',
        ] );
    }
}
