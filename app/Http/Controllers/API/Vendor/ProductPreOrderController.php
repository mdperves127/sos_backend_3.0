<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPreOrder;
use App\Services\ProductPreOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductPreOrderController extends Controller
{
    public function index()
    {
        $items = ProductPreOrder::query()
            ->with( [
                'product' => function ( $q ) {
                    $q->select(
                        'id', 'name', 'slug', 'image', 'sku', 'selling_price', 'discount_price',
                        'pre_order', 'advance_payment', 'qty', 'status'
                    );
                },
            ] )
            ->when( request( 'status' ), fn ( $q, $status ) => $q->where( 'status', $status ) )
            ->when( request()->filled( 'search' ), function ( $q ) {
                $search = request( 'search' );
                $q->whereHas( 'product', function ( $p ) use ( $search ) {
                    $p->where( 'name', 'like', '%' . $search . '%' )
                        ->orWhere( 'sku', 'like', '%' . $search . '%' );
                } );
            } )
            ->latest( 'id' )
            ->paginate( 10 )
            ->withQueryString();

        $items->getCollection()->transform( function ( ProductPreOrder $row ) {
            return [
                'id'                     => $row->id,
                'product'                => $row->product,
                'expected_delivery_date' => optional( $row->expected_delivery_date )->format( 'Y-m-d' ),
                'quantity_limit'         => (int) $row->quantity_limit,
                'quantity_ordered'       => (int) $row->quantity_ordered,
                'remaining_quantity'     => (int) $row->remaining_quantity,
                'advance_amount'         => (float) $row->advance_amount,
                'payment_options'        => $row->payment_options,
                'status'                 => $row->status,
                'pre_order_enabled'      => (string) ( $row->product?->pre_order ?? '0' ) === '1',
            ];
        } );

        return response()->json( [
            'status' => 200,
            'data'   => $items,
        ] );
    }

    public function show( $productId )
    {
        $product = Product::where( 'id', $productId )->where( 'user_id', vendorId() )->first()
            ?? Product::where( 'id', $productId )->where( 'vendor_id', vendorId() )->first();

        if ( ! $product ) {
            $product = Product::find( $productId );
        }

        if ( ! $product ) {
            return response()->json( ['status' => 404, 'message' => 'Product not found.'], 404 );
        }

        $settings = ProductPreOrder::where( 'product_id', $product->id )->first();

        $orders = Order::where( 'product_id', $product->id )
            ->where( 'is_pre_order', true )
            ->latest( 'id' )
            ->paginate( 10 );

        $orders->getCollection()->transform( function ( Order $order ) {
            return $this->formatOrder( $order );
        } );

        return response()->json( [
            'status' => 200,
            'data'   => [
                'product'  => $product->only( [
                    'id', 'name', 'slug', 'image', 'sku', 'selling_price', 'discount_price',
                    'pre_order', 'advance_payment', 'qty', 'status',
                ] ),
                'settings' => $settings ? [
                    'expected_delivery_date' => optional( $settings->expected_delivery_date )->format( 'Y-m-d' ),
                    'quantity_limit'         => (int) $settings->quantity_limit,
                    'quantity_ordered'       => (int) $settings->quantity_ordered,
                    'remaining_quantity'     => (int) $settings->remaining_quantity,
                    'advance_amount'         => (float) $settings->advance_amount,
                    'payment_options'        => $settings->payment_options,
                    'status'                 => $settings->status,
                ] : null,
                'orders'   => $orders,
            ],
        ] );
    }

    public function updateSettings( Request $request, $productId )
    {
        $product = Product::find( $productId );
        if ( ! $product ) {
            return response()->json( ['status' => 404, 'message' => 'Product not found.'], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'pre_order'              => ['nullable', Rule::in( ['0', '1', 0, 1, true, false, 'true', 'false'] )],
            'enabled'                => ['nullable', 'boolean'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'quantity_limit'         => ['nullable', 'integer', 'min:1'],
            'advance_amount'         => ['nullable', 'numeric', 'min:0'],
            'payment_options'        => ['nullable', Rule::in( ['advance', 'full', 'both'] )],
            'status'                 => ['nullable', Rule::in( ['active', 'closed'] )],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        try {
            $settings = ProductPreOrderService::saveSettings( $product, $validator->validated() );
        } catch ( ValidationException $e ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $e->errors(),
            ], 400 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Pre-order settings saved.',
            'data'    => [
                'product_id'             => $product->id,
                'pre_order'              => $product->fresh()->pre_order,
                'expected_delivery_date' => optional( $settings->expected_delivery_date )->format( 'Y-m-d' ),
                'quantity_limit'         => (int) $settings->quantity_limit,
                'quantity_ordered'       => (int) $settings->quantity_ordered,
                'remaining_quantity'     => (int) $settings->remaining_quantity,
                'advance_amount'         => (float) $settings->advance_amount,
                'payment_options'        => $settings->payment_options,
                'status'                 => $settings->status,
            ],
        ] );
    }

    public function orders()
    {
        $orders = Order::query()
            ->where( 'is_pre_order', true )
            ->when( request( 'status' ), fn ( $q, $s ) => $q->where( 'status', $s ) )
            ->when( request( 'product_id' ), fn ( $q, $id ) => $q->where( 'product_id', $id ) )
            ->when( request()->filled( 'search' ), function ( $q ) {
                $search = request( 'search' );
                $q->where( function ( $inner ) use ( $search ) {
                    $inner->where( 'order_id', 'like', '%' . $search . '%' )
                        ->orWhere( 'name', 'like', '%' . $search . '%' )
                        ->orWhere( 'phone', 'like', '%' . $search . '%' );
                } );
            } )
            ->with( ['product:id,name,image,sku'] )
            ->latest( 'id' )
            ->paginate( 10 )
            ->withQueryString();

        $orders->getCollection()->transform( fn ( Order $order ) => $this->formatOrder( $order ) );

        return response()->json( [
            'status' => 200,
            'data'   => $orders,
        ] );
    }

    /**
     * Collect remaining due on a pre-order (reuses order paid_amount / due_amount).
     */
    public function collectDue( Request $request, $orderId )
    {
        $order = Order::where( 'id', $orderId )->where( 'is_pre_order', true )->first();
        if ( ! $order ) {
            return response()->json( ['status' => 404, 'message' => 'Pre-order not found.'], 404 );
        }

        $validator = Validator::make( $request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note'   => 'nullable|string|max:1000',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        $amount = round( (float) $request->amount, 2 );
        $due    = round( (float) ( $order->due_amount ?? 0 ), 2 );

        if ( $due <= 0 ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'No remaining due on this pre-order.',
            ], 400 );
        }

        if ( $amount > $due + 0.001 ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Amount exceeds remaining due.',
            ], 400 );
        }

        $order->paid_amount = round( (float) ( $order->paid_amount ?? 0 ) + $amount, 2 );
        $order->due_amount  = max( 0, round( $due - $amount, 2 ) );
        if ( $request->filled( 'note' ) ) {
            $order->additional_note = trim( (string) $order->additional_note . "\nDue collected: {$amount}. " . $request->note );
        }
        $order->save();

        if ( $order->user_id ) {
            ProductPreOrderService::notifyCustomer( (int) $order->user_id, $order, 'payment_received' );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Payment recorded successfully.',
            'data'    => $this->formatOrder( $order->fresh( ['product:id,name'] ) ),
        ] );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrder( Order $order ): array
    {
        return [
            'id'                     => $order->id,
            'order_id'               => $order->order_id,
            'customer_name'          => $order->name,
            'phone'                  => $order->phone,
            'email'                  => $order->email,
            'product'                => $order->relationLoaded( 'product' ) ? $order->product : null,
            'qty'                    => (int) $order->qty,
            'product_amount'         => (float) $order->product_amount,
            'paid_amount'            => (float) ( $order->paid_amount ?? 0 ),
            'totaladvancepayment'    => (float) ( $order->totaladvancepayment ?? 0 ),
            'due_amount'             => (float) ( $order->due_amount ?? 0 ),
            'status'                 => $order->status,
            'lifecycle'              => ProductPreOrderService::lifecycleFromOrderStatus( (string) $order->status ),
            'expected_delivery_date' => optional( $order->expected_delivery_date )->format( 'Y-m-d' )
                ?? ( is_string( $order->expected_delivery_date ) ? $order->expected_delivery_date : null ),
            'pre_order_payment_type' => $order->pre_order_payment_type,
            'created_at'             => optional( $order->created_at )->toDateTimeString(),
        ];
    }
}
