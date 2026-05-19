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

class OrderController extends Controller {
    function allOrders() {
        // if ( checkpermission( 'all-order' ) != 1 ) {
        //     return $this->permissionmessage();
        // }

        $search = request( 'search' );
        $status = request( 'status' );

        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $search, $status ) {
                if ( $status && ! in_array( $status, ['', 'all'], true ) ) {
                    $query->where( 'orders.status', $status );
                }

                if ( $search ) {
                    $query->leftJoin( 'products', 'orders.product_id', '=', 'products.id' )
                        ->where( function ( $q ) use ( $search ) {
                            $q->where( 'orders.order_id', 'like', "%{$search}%" )
                                ->orWhere( 'products.name', 'like', "%{$search}%" );
                        } )
                        ->select( 'orders.*' )
                        ->groupBy( 'orders.id' );
                }

                $query->orderBy( 'orders.created_at', 'desc' );
            }
        );

        $orders = collect( $allOrders )
            ->map( fn ( $order ) => $this->enrichOrderFromTenant( $order ) )
            ->sortByDesc( fn ( $order ) => $order->created_at ?? '' )
            ->values();

        return response()->json( [
            'status'  => 200,
            'message' => CrossTenantQueryService::paginateCollection( $orders ),
        ] );
    }

    /**
     * Load product, vendor, affiliator, and ratings from the order's tenant database.
     */
    private function enrichOrderFromTenant( object $order ): object {
        if ( isset( $order->variants ) && is_string( $order->variants ) ) {
            $order->variants = json_decode( $order->variants );
        }

        if ( ! isset( $order->tenant_id, $order->product_id ) ) {
            return $order;
        }

        $tenant = Tenant::on( 'mysql' )->find( $order->tenant_id );

        if ( ! $tenant ) {
            return $order;
        }

        try {
            $connectionName = CrossTenantQueryService::connectionForTenant( $tenant );

            $order->product = Product::on( $connectionName )
                ->select( 'id', 'name' )
                ->find( $order->product_id );

            if ( isset( $order->vendor_id ) ) {
                $order->vendor = User::on( $connectionName )
                    ->select( 'id', 'name' )
                    ->find( $order->vendor_id );
            }

            if ( isset( $order->affiliator_id ) ) {
                $order->affiliator = User::on( $connectionName )
                    ->select( 'id', 'name' )
                    ->find( $order->affiliator_id );
            }

            $order->productrating = ProductRating::on( $connectionName )
                ->where( 'order_id', $order->id )
                ->get();
        } catch ( \Throwable $e ) {
            report( $e );
        }

        return $order;
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
