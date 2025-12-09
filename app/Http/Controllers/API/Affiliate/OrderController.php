<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentStore;
use App\Models\Product;
use App\Models\User;
use App\Models\Tenant;
use App\Services\AamarPayService;
use App\Services\CrossTenantQueryService;
use App\Services\ProductCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ProductRating;

class OrderController extends Controller {

    function store( ProductRequest $request ) {

        $cart = Cart::where('id', $request->cart_id)->first();

        if ( !$cart || !$cart->tenant_id ) {
            return responsejson( 'Cart not found or missing tenant information', 'fail' );
        }

        // Get product from cart's tenant database
        $product = CrossTenantQueryService::getSingleFromTenant(
            $cart->tenant_id,
            Product::class,
            function ( $query ) use ( $cart ) {
                $query->where( ['id' => $cart->product_id, 'status' => 'active'] );
            }
        );

        if ( !$product ) {
            return responsejson( 'Product currently not available!' );
        }

        if ( $cart->purchase_type == 'single' ) {
            if ( $product->selling_type == 'bulk' ) {
                return responsejson( 'Something is wrong delete the cart.', 'fail' );
            }
        }

        $datas = collect( request( 'datas' ) );

        if ( $cart->purchase_type == 'bulk' ) {
            $firstaddress = $datas->first();
            $variants     = collect( $firstaddress )['variants'];
            $totalqty     = collect( $variants )->sum( 'qty' );

            if ( $product->is_connect_bulk_single == 1 ) {
                if ( $product->qty < $totalqty ) {
                    return responsejson( 'Product quantity not available!', 'fail' );
                }
            }
        }

        if ( $cart->purchase_type == 'single' ) { //single

            $varients = $datas->pluck( 'variants' );

            $totalqty = collect( $varients )->collapse()->sum( 'qty' );

            if ( $product->qty < $totalqty ) {
                return responsejson( 'Product quantity not available!', 'fail' );
            }
        }

        if ( $product->status == Status::Pending->value ) {
            return responsejson( 'The product under construction!', 'fail' );
        }

        $uservarients = collect( request()->datas )->pluck( 'variants' )->collapse();

        if ( $product->variants != '' ) {
            if ( ( $cart->purchase_type != 'bulk' ) && ( $product->is_connect_bulk_single != 1 ) ) {
                foreach ( $uservarients as $vr ) {
                    $data = collect( $product?->productVariant?->variants )->where( 'id', $vr['variant_id'] )->where( 'qty', '>=', $vr['qty'] )->first();
                    if ( !$data ) {
                        return responsejson( 'Something is wrong. Delete the cart', 'fail' );
                    }
                }
            }

        }

        // Get user - using auth()->user() or auth()->id() as needed
        $user = auth()->user();
        $currentTenant = tenant();
        $advancepayment = $cart->advancepayment * $totalqty;


        if ( request( 'payment_type' ) == 'my-wallet' ) {
            if ( $currentTenant->balance < $advancepayment ) {
                return responsejson( 'You do not have sufficient balance.', 'fail' );
            }
            $currentTenant->decrement( 'balance', $advancepayment );

            return ProductCheckoutService::store( $cart->id, $product->id, $totalqty, $user->id, request( 'datas' ), 'aamarpay', $cart->tenant_id );
        } elseif ( request( 'payment_type' ) == 'aamarpay' ) {
            $trx = uniqid();
            PaymentStore::create( [
                'payment_gateway' => 'aamarpay',
                'trxid'           => $trx,
                'status'          => 'pending',
                'last_status'     => 'pending',
                'order_media'     => 'Affiliator',
                'payment_type'    => 'checkout',
                'info'            => [
                    'cartid'    => $cart->id,
                    'productid' => $product->id,
                    'totalqty'  => $totalqty,
                    'userid'    => $user->id,
                    'datas'     => request( 'datas' ),
                    'tenant_id' => $cart->tenant_id,
                ],
                ] );
            $successurl = url( 'api/aaparpay/product-checkout-success' );
            return AamarPayService::gateway( $advancepayment, $trx, 'Product Checkout', $successurl );
        }
    }

    function pendingOrders() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by pending status
                $query->where( 'status', Status::Pending->value );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }

    function ProgressOrders() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by progress status
                $query->where( 'status', Status::Progress->value );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }
    function receivedOrders() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by received status
                $query->where( 'status', 'received' );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }

    function DeliveredOrders() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by delivered status
                $query->where( 'status', Status::Delivered->value );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }
    function CanceldOrders() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by cancel status
                $query->where( 'status', Status::Cancel->value );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }

    function ProductProcessing() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by processing status
                $query->where( 'status', Status::Processing->value );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }

    function OrderReady() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by ready status
                $query->where( 'status', Status::Ready->value );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }

    function orderReturn() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by return status
                $query->where( 'status', Status::Return->value );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }

    function AllOrders() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load affiliator
                    if ( isset( $order->affiliator_id ) ) {
                        $affiliator = User::on( $connectionName )->select( 'id', 'name' )->find( $order->affiliator_id );
                        $order->affiliator = $affiliator;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }

    function orderView( $id ) {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;

        // Query order from all merchant tenant databases
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $id, $tenantId ) {
                $query->where( 'id', $id );
                // Filter by tenant_id
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }
            }
        );

        $order = $allOrders->first();

        if ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually
            if ( isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
                if ( $tenant ) {
                    $connectionName = 'tenant_' . $tenant->id;
                    $databaseName = 'sosanik_tenant_' . $tenant->id;

                    // Configure connection
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

                    // Load product with relationships
                    $product = Product::on( $connectionName )
                        ->with( [
                            'category:id,name',
                            'subcategory:id,name',
                            'brand:id,name',
                        ] )
                        ->find( $order->product_id );
                    $order->product = $product;

                    // Load product ratings with affiliate
                    $productRatings = ProductRating::on( $connectionName )
                        ->with( 'affiliate:id,name' )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return response()->json( [
                'status'  => 200,
                'message' => $order,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'Not found',
            ] );
        }
    }
    function HoldOrders() {
        $currentTenant = tenant();
        $tenantId = $currentTenant ? $currentTenant->id : null;
        $search = request( 'search' );

        // Query orders from all merchant tenant databases (get all results first)
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $search ) {
                // Filter by tenant_id (query is already on orders table)
                if ( $tenantId ) {
                    $query->where( 'tenant_id', $tenantId );
                }

                // Filter by hold status
                $query->where( 'status', Status::Hold->value );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                          } )
                          ->select( 'orders.*' )
                          ->groupBy( 'orders.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $orders = collect( $allOrders )->map( function ( $order ) {
            // Decode variants
            if ( isset( $order->variants ) ) {
                $order->variants = json_decode( $order->variants );
            }

            // Load relationships manually for each order
            if ( isset( $order->product_id ) && isset( $order->tenant_id ) ) {
                $tenant = Tenant::find( $order->tenant_id );
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
                    $product = Product::on( $connectionName )->select( 'id', 'name' )->find( $order->product_id );
                    $order->product = $product;

                    // Load vendor
                    if ( isset( $order->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $order->vendor_id );
                        $order->vendor = $vendor;
                    }

                    // Load product ratings
                    $productRatings = ProductRating::on( $connectionName )
                        ->where( 'order_id', $order->id )
                        ->get();
                    $order->productrating = $productRatings;
                }
            }

            return $order;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $orders = $orders->sortByDesc( function ( $order ) {
            return $order->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedOrders = $orders->slice( $offset, $perPage );
        $lastPage = ceil( $orders->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedOrders->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $orders->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $orders->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $lastPage > 0 ? $buildUrl( $lastPage ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }
}
