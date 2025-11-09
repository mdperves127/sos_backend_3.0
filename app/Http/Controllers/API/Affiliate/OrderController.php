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
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', Status::Pending->value )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', Status::Progress->value )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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
    function receivedOrders() {
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', 'received' )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', Status::Delivered->value )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', Status::Cancel->value )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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

    function ProductProcessing() {
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', Status::Processing->value )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', Status::Ready->value )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', Status::Return ->value )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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

    function AllOrders() {
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->with( ['product:id,name', 'vendor:id,name', 'affiliator:id,name', 'productrating'] )
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

    function orderView( $id ) {
        $order = Order::where( 'id', $id )->where( 'affiliator_id', auth()->user()->id )->first();
        if ( $order ) {

            $allData =
            Order::query()
                ->with( [
                    'productrating.affiliate',
                    'product',
                    'product.category:id,name',
                    'product.subcategory:id,name',
                    'product.brand:id,name',
                ] )->find( $id );

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
        $orders = Order::searchProduct()
            ->where( 'affiliator_id', auth()->user()->id )
            ->where( 'status', Status::Hold->value )
            ->with( ['product:id,name', 'vendor:id,name', 'productrating'] )
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
}
