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
            $order->variants = Order::normalizeVariants( $order->variants );
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

    private function tenantOrdersListing( string $status, ?string $permissionKey = null ) {
        if ( $permissionKey && checkpermission( $permissionKey ) != 1 ) {
            return $this->permissionmessage();
        }

        $search = request( 'search' );

        $allOrders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $search, $status ) {
                $query->where( 'orders.status', $status );

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

    function ProductProcessing() {
        return $this->tenantOrdersListing( Status::Processing->value, 'order-processing' );
    }

    function OrderReady() {
        return $this->tenantOrdersListing( Status::Ready->value, 'order-ready' );
    }

    function orderReturn() {
        return $this->tenantOrdersListing( Status::Return ->value, 'order-return' );
    }

    function pendingOrders() {
        return $this->tenantOrdersListing( Status::Pending->value, 'order-pending' );
    }

    function ProgressOrders() {
        return $this->tenantOrdersListing( Status::Progress->value, 'delivery-processing' );
    }
    function ReceivedOrders() {
        return $this->tenantOrdersListing( 'received', 'order-received' );
    }

    function DeliveredOrders() {
        return $this->tenantOrdersListing( Status::Delivered->value, 'product-delivered' );
    }

    function CanceldOrders() {
        return $this->tenantOrdersListing( Status::Cancel->value, 'order-cancel' );
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

            $allData->variants = Order::normalizeVariants( $allData->variants );

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
        return $this->tenantOrdersListing( Status::Hold->value, 'order-hold' );
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
