<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductOrderRequest;
use App\Models\DeliveryCompany;
use App\Models\Order;
use App\Services\ProductOrderService;
use App\Models\Tenant;
use App\Services\CrossTenantQueryService;
use App\Models\Product;
use App\Models\User;
use App\Models\ProductRating;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller {
    function allOrders() {
        // if ( checkpermission( 'all-order' ) != 1 ) {
        //     return $this->permissionmessage();
        // }

        $search = request( 'search' );

        // Query orders from all merchant tenant databases
        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $search ) {
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
            'last_page_url' => $buildUrl( $lastPage ),
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'message' => $response,
        ] );
    }

    function ProductProcessing() {
        if ( checkpermission( 'order-processing' ) != 1 ) {
            return $this->permissionmessage();
        }
        $orders = Order::searchProduct()
            ->where( 'status', Status::Processing->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function OrderReady() {

        if ( checkpermission( 'order-ready' ) != 1 ) {
            return $this->permissionmessage();
        }

        $orders = Order::searchProduct()
            ->where( 'status', Status::Ready->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function orderReturn() {

        if ( checkpermission( 'order-return' ) != 1 ) {
            return $this->permissionmessage();
        }
        $orders = Order::searchProduct()
            ->where( 'status', Status::Return ->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function pendingOrders() {
        if ( checkpermission( 'order-pending' ) != 1 ) {
            return $this->permissionmessage();
        }

        $orders = Order::searchProduct()
            ->where( 'status', Status::Pending->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function ProgressOrders() {
        if ( checkpermission( 'delivery-processing' ) != 1 ) {
            return $this->permissionmessage();
        }

        $orders = Order::searchProduct()
            ->where( 'status', Status::Progress->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )

            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }
    function ReceivedOrders() {
        if ( checkpermission( 'order-received' ) != 1 ) {
            return $this->permissionmessage();
        }

        $orders = Order::searchProduct()
            ->where( 'status', 'received' )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )

            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function DeliveredOrders() {
        if ( checkpermission( 'product-delivered' ) != 1 ) {
            return $this->permissionmessage();
        }

        $orders = Order::searchProduct()
            ->where( 'status', Status::Delivered->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function CanceldOrders() {
        if ( checkpermission( 'order-cancel' ) != 1 ) {
            return $this->permissionmessage();
        }

        $orders = Order::searchProduct()
            ->where( 'status', Status::Cancel->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function updateStatus( ProductOrderRequest $request, $id ) {
        $validatedData = $request->validated();
        return ProductOrderService::orderStatus( $validatedData, $id );
    }

    // function updateStatus( Request $request, $id ) {
    //     $validatedData = $request->all();
    //     return ProductOrderService::orderStatus( $validatedData, $id );
    // }

    function orderView( $id ) {
        $order = Order::find( $id );

        if ( $order ) {

            $allData = $order->load( [
                'product',
                'product.category:id,name',
                'product.subcategory:id,name',
                'product.brand:id,name',
                'vendor',
                'affiliator',
            ] );

            $allData->variants = json_decode( $allData->variants );

            return response()->json( [
                'status'  => 200,
                'message' => $allData,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'Not found',
            ] );
        }
    }

    function HoldOrders() {
        if ( checkpermission( 'order-hold' ) != 1 ) {
            return $this->permissionmessage();
        }

        $orders = Order::searchProduct()
            ->where( 'status', Status::Hold->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    public function getDeliveryCompany( $vendorId ) {
        $data = DeliveryCompany::where( 'vendor_id', $vendorId )->whereStatus( 'active' )->select( 'id', 'company_name', 'phone' )->get();

        if ( !$data ) {
            return response()->json( [
                'status'  => 404,
                'message' => "No data found !",
            ] );
        }

        return response()->json( [
            'status'          => 200,
            'deliveryCompany' => $data,
        ] );
    }
}
