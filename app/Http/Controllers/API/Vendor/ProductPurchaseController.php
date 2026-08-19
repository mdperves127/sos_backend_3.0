<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Color;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\ProductPurchaseDetails;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\VendorInfo;
use App\Service\Vendor\ProductPurchaseService;
use App\Service\Vendor\ProductVariantService;
use App\Service\Vendor\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductPurchaseController extends Controller {

    public function index() {
        return response()->json( [
            'status'           => 200,
            'product_purchase' => ProductPurchaseService::index(),
        ] );
    }

    function create() {

        $data = [
            'supplier'       => Supplier::latest()
                ->where( 'status', 'active' )
                ->where( 'vendor_id', vendorId() )
                ->select( 'id', 'supplier_name', 'business_name' )
                ->get(),
            'products'       => Product::latest()
                ->where( 'vendor_id', vendorId() )
                ->where( 'status', 'active' )
                ->select( 'id', 'name', 'sku', 'original_price', 'qty', 'supplier_id' )
                ->get(),
            'unit'           => Unit::where( ['status' => 'active'] )->select( 'id', 'unit_name' )->get(),
            'color'          => Color::where( ['status' => 'active'] )->select( 'id', 'name' )->get(),
            'variation'      => Size::where( ['status' => 'active'] )->select( 'id', 'name' )->get(),
            'payment_method' => PaymentMethod::where( ['status' => 'active'] )->select( 'id', 'payment_method_name', 'acc_no' )->get(),
        ];

        return response()->json( [
            'status'    => 200,
            'data'      => $data,
            'chalan_no' => str_pad( rand( 0, 99999 ), 5, '0', STR_PAD_LEFT ),
        ] );
    }

    function supplierProduct( $supplier_id ) {
        $supplier = Supplier::where( 'id', $supplier_id )
            ->where( 'vendor_id', vendorId() )
            ->where( 'status', 'active' )
            ->select( 'id', 'supplier_name', 'business_name' )
            ->first();

        if ( ! $supplier ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Supplier not found or inactive.',
            ], 404 );
        }

        $products = Product::latest()
            ->where( 'vendor_id', vendorId() )
            ->where( 'status', 'active' )
            ->when( request( 'search' ), function ( $query, $search ) {
                $query->where( function ( $q ) use ( $search ) {
                    $q->where( 'name', 'like', '%' . $search . '%' )
                        ->orWhere( 'sku', 'like', '%' . $search . '%' );
                } );
            } )
            ->select( 'id', 'name', 'sku', 'original_price', 'qty', 'supplier_id' )
            ->get();

        return response()->json( [
            'status'   => 200,
            'supplier' => $supplier,
            'products' => $products,
        ] );
    }

    public function store( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'chalan_no'     => 'required|unique:product_purchases',
            'supplier_id'   => [
                'required',
                'integer',
                function ( $attribute, $value, $fail ) {
                    $exists = Supplier::where( 'id', $value )
                        ->where( 'vendor_id', vendorId() )
                        ->where( 'status', 'active' )
                        ->exists();
                    if ( ! $exists ) {
                        $fail( 'Selected supplier is invalid or inactive.' );
                    }
                },
            ],
            'purchase_date' => 'required|date',
            'status'        => 'required|in:ordered,received',
            'payment_id'    => 'required',
            'paid_amount'   => 'numeric|min:0',
            'total_price'   => 'numeric|min:0',
            'product_id'    => 'required|array|min:1',
            'product_id.*'  => [
                'required',
                'integer',
                function ( $attribute, $value, $fail ) {
                    $exists = Product::where( 'id', $value )
                        ->where( 'vendor_id', vendorId() )
                        ->exists();
                    if ( ! $exists ) {
                        $fail( 'One or more selected products are invalid.' );
                    }
                },
            ],
            'unit_id'       => 'required|array',
            'unit_id.*'     => 'required',
            'rate'          => 'required|array',
            'rate.*'        => 'required|numeric|min:0',
            'sub_total'     => 'required|array',
            'sub_total.*'   => 'required|numeric|min:0',
            'qty'           => 'required|array',
            'qty.*'         => 'required|integer|min:1',
        ], [
            'chalan_no.required'           => 'Chalan number is required.',
            'chalan_no.unique'             => 'Chalan number must be unique.',
            'supplier_id.required'         => 'Select the supplier you purchased from.',
            'supplier_id.exists'           => 'Selected supplier is invalid or inactive.',
            'purchase_date.required'       => 'Purchase date is required.',
            'purchase_date.date'           => 'Purchase date must be a valid date.',
            'status.required'              => 'Status is required.',
            'payment_id.required'          => 'Payment ID is required.',
            'paid_amount.numeric'          => 'Paid amount must be numeric.',
            'total_price.numeric'          => 'Total price must be numeric.',
            'product_id.required'          => 'Select at least one product.',
            'product_id.*.required'        => 'Product ID is required for all details.',
            'product_id.*.exists'          => 'One or more selected products are invalid.',
            'unit_id.*.required'           => 'Unit ID is required for all details.',
            'rate.*.required'              => 'Rate is required for all details.',
            'rate.*.numeric'               => 'Rate must be numeric for all details.',
            'rate.*.min'                   => 'Rate must be at least 0 for all details.',
            'sub_total.*.required'         => 'Subtotal is required for all details.',
            'sub_total.*.numeric'          => 'Subtotal must be numeric for all details.',
            'sub_total.*.min'              => 'Subtotal must be at least 0 for all details.',
            'qty.*.required'               => 'Quantity is required for all details.',
            'qty.*.integer'                => 'Quantity must be an integer for all details.',
            'qty.*.min'                    => 'Quantity must be at least 1 for all details.',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        try {
            $purchase = DB::transaction( function () use ( $request ) {
                $productIds = $request->product_id;
                $totalQty   = $request->total_qty ?: array_sum( array_map( 'intval', (array) $request->qty ) );
                $dueAmount  = $request->due_amount;
                if ( $dueAmount === null ) {
                    $dueAmount = max( 0, (float) $request->total_price - (float) $request->paid_amount );
                }

                $purchase                    = new ProductPurchase();
                $purchase->supplier_id       = $request->supplier_id;
                $purchase->chalan_no         = $request->chalan_no;
                $purchase->user_id           = Auth::id();
                $purchase->purchase_date     = $request->purchase_date;
                $purchase->payment_id        = $request->payment_id;
                $purchase->paid_amount       = $request->paid_amount;
                $purchase->total_qty         = $totalQty;
                $purchase->total_price       = $request->total_price;
                $purchase->due_amount        = $dueAmount;
                $purchase->purchase_discount = $request->purchase_discount;
                $purchase->status            = $request->status;
                $purchase->payment_status    = (float) $request->total_price == (float) $request->paid_amount ? 'paid' : 'due';
                $purchase->vendor_id         = vendorId();
                $purchase->note              = $request->note;
                $purchase->save();

                foreach ( $productIds as $key => $product_id ) {
                    $purchaseDetails                      = new ProductPurchaseDetails();
                    $purchaseDetails->product_purchase_id = $purchase->id;
                    $purchaseDetails->product_id          = $product_id;
                    $purchaseDetails->unit_id             = $request->unit_id[$key];
                    $purchaseDetails->size_id             = $request->size_id[$key] ?? null;
                    $purchaseDetails->color_id            = $request->color_id[$key] ?? null;
                    $purchaseDetails->qty                 = $request->qty[$key];
                    $purchaseDetails->rate                = $request->rate[$key];
                    $purchaseDetails->sub_total           = $request->sub_total[$key];
                    $purchaseDetails->save();
                }

                Product::whereIn( 'id', $productIds )
                    ->where( 'vendor_id', vendorId() )
                    ->update( ['supplier_id' => $request->supplier_id] );

                ProductService::productVariants( $productIds, $request->all(), $request->status );

                if ( $request->paid_amount > 0 ) {
                    $purchase['partial_payment'] = 0;
                    ProductPurchaseService::supplierPayment( $purchase );
                }

                return $purchase;
            } );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => 'Product purchase failed: ' . $e->getMessage(),
            ], 500 );
        }

        return response()->json( [
            'status'      => 200,
            'message'     => 'Product successfully purchased from supplier.',
            'purchase_id' => $purchase->id,
            'supplier_id' => (int) $purchase->supplier_id,
        ] );
    }

    public function show( $id ) {
        return response()->json( [
            'status'        => 200,
            'logo'          => VendorInfo::first(),
            'purchase_show' => ProductPurchaseService::show( $id ),
        ] );
    }

    public function status( $id ) {
        $purchase = ProductPurchase::find( $id );

        if ( !$purchase ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product purchase not found.',
            ] );
        }

        if ( $purchase->status == 'received' ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Product already received.',
            ] );
        }

        $productDetails = ProductPurchaseDetails::where( 'product_purchase_id', $purchase->id )->get();

        foreach ( $productDetails as $productDetail ) {
            $receivedQty = (int) $productDetail->qty;

            $productVariant = ProductVariantService::findOrCreateVariant(
                (int) $productDetail->product_id,
                ProductVariantService::normalizeNullableId( $productDetail->unit_id ),
                ProductVariantService::normalizeNullableId( $productDetail->size_id ),
                ProductVariantService::normalizeNullableId( $productDetail->color_id ),
                vendorId()
            );
            $productVariant->qty = (int) ( $productVariant->qty ?? 0 ) + $receivedQty;
            $productVariant->save();

            // Update qty for the product
            $product = Product::find( $productDetail->product_id );
            if ( $product ) {
                ProductVariantService::syncJsonFromDb( $product );
                ProductVariantService::recalculateProductQty( $product );
            }
        }

        $purchase->status = 'received';
        $purchase->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Product successfully received.',
        ] );
    }

    public function addPayment( $id ) {
        $purchase = ProductPurchase::find( $id );

        if ( $purchase == null ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Invoice not found.',
            ] );
        }

        if ( $purchase->payment_status == 'paid' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'There are no outstanding payments. Thank you!',
            ] );
        }

        if ( $purchase->due_amount < request()->amount ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'The amount you entered exceeds the due amount.',
            ] );
        }

        $purchase['partial_payment']        = 1;
        $purchase['partial_payment_amount'] = request()->amount;
        $purchase['payment_method']         = request()->payment_method_id;
        ProductPurchaseService::supplierPayment( $purchase );

        return response()->json( [
            'status'  => 200,
            'message' => 'Payment successfully complete !',
        ] );
    }

    public function paymentHistory() {
        $userId = Auth::id();

        $payment_histories = SupplierPayment::where( 'user_id', $userId )
            ->when( request( 'supplier_id' ), function ( $q, $supplierId ) {
                return $q->where( 'supplier_id', $supplierId );
            } )
            ->when( request()->filled( 'search' ), function ( $query ) {
                $search = request()->input( 'search' );
                $query->where( function ( $q ) use ( $search ) {
                    $q->where( 'chalan_no', 'like', '%' . $search . '%' );
                } );
            } )
            ->when( request( 'start_date' ) && request( 'end_date' ), function ( $q ) {
                $startDate = request( 'start_date' );
                $endDate   = request( 'end_date' );
                return $q->whereBetween( 'date', [$startDate, $endDate] );
            } )
            ->select( 'id', 'chalan_no', 'product_purchase_id', 'supplier_id', 'date', 'payment_method_id', 'paid_amount' )
            ->latest()
            ->with( ['supplier' => function ( $query ) {
                $query->select( 'id', 'supplier_name' );
            }] )
            ->with( ['paymentMethod' => function ( $query ) {
                $query->select( 'id', 'payment_method_name' );
            }] )
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'          => 200,
            'payment_history' => $payment_histories,
        ] );

    }

}
