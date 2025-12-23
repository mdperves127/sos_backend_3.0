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
            'supplier'       => Supplier::latest()->where( 'vendor_id', vendorId() )->where( 'status', 'active' )->select( 'id', 'supplier_name', 'business_name' )->get(),
            'unit'           => Unit::where( ['status' => 'active', 'vendor_id' => vendorId()] )->select( 'id', 'unit_name' )->get(),
            'color'          => Color::where( ['status' => 'active', 'vendor_id' => vendorId()] )->select( 'id', 'name' )->get(),
            'variation'      => Size::where( ['status' => 'active', 'vendor_id' => vendorId()] )->select( 'id', 'name' )->get(),
            'payment_method' => PaymentMethod::where( ['status' => 'active', 'vendor_id' => vendorId()] )->select( 'id', 'payment_method_name', 'acc_no' )->get(),
        ];

        return response()->json( [
            'status'    => 200,
            'data'      => $data,
            'chalan_no' => str_pad( rand( 0, 99999 ), 5, '0', STR_PAD_LEFT ),
        ] );
    }

    function supplierProduct( $supplier_id ) {
        return response()->json( [
            'status'   => 200,
            'products' => Product::latest()->where( 'supplier_id', $supplier_id )->select( 'id', 'name', 'original_price' )->where( ['status' => 'active'] )->get(),
        ] );
    }

    public function store( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'chalan_no'     => 'required|unique:product_purchases',
            'supplier_id'   => 'required',
            'purchase_date' => 'required|date|after_or_equal:today|before_or_equal:' . now()->addDays( 365 )->format( 'Y-m-d' ),
            'status'        => 'required',
            'payment_id'    => 'required',
            'paid_amount'   => 'numeric',
            'total_price'   => 'numeric',
            'product_id'    => 'required|array', // Ensure product_id is an array
            'product_id.*'  => 'required', // Ensure each product_id element is required
            'unit_id'       => 'required|array', // Ensure unit_id is an array
            'unit_id.*'     => 'required', // Ensure each unit_id element is required
            'rate'          => 'required|array', // Ensure rate is an array
            'rate.*'        => 'required|numeric|min:0', // Ensure each rate element is required, numeric, and >= 0
            'sub_total'     => 'required|array', // Ensure sub_total is an array
            'sub_total.*'   => 'required|numeric|min:0', // Ensure each sub_total element is required, numeric, and >= 0
            'qty'           => 'required|array', // Ensure qty is an array
            'qty.*'         => 'required|integer|min:1', // Ensure each qty element is required, integer, and >= 1
        ], [
            'chalan_no.required'            => 'Chalan number is required.',
            'chalan_no.unique'              => 'Chalan number must be unique.',
            'supplier_id.required'          => 'Supplier ID is required.',
            'purchase_date.required'        => 'Purchase date is required.',
            'purchase_date.date'            => 'Purchase date must be a valid date.',
            'purchase_date.after_or_equal'  => 'Purchase date must be today or later.',
            'purchase_date.before_or_equal' => 'Purchase date must be within the next year.',
            'status.required'               => 'Status is required.',
            'payment_id.required'           => 'Payment ID is required.',
            'paid_amount.numeric'           => 'Paid amount must be numeric.',
            'total_price.numeric'           => 'Total price must be numeric.',
            'product_id.*.required'         => 'Product ID is required for all details.',
            'unit_id.*.required'            => 'Unit ID is required for all details.',
            'rate.*.required'               => 'Rate is required for all details.',
            'rate.*.numeric'                => 'Rate must be numeric for all details.',
            'rate.*.min'                    => 'Rate must be at least 0 for all details.',
            'sub_total.*.required'          => 'Subtotal is required for all details.',
            'sub_total.*.numeric'           => 'Subtotal must be numeric for all details.',
            'sub_total.*.min'               => 'Subtotal must be at least 0 for all details.',
            'qty.*.required'                => 'Quantity is required for all details.',
            'qty.*.integer'                 => 'Quantity must be an integer for all details.',
            'qty.*.min'                     => 'Quantity must be at least 1 for all details.',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        } else {
            $purchase                    = new ProductPurchase();
            $purchase->supplier_id       = $request->supplier_id;
            $purchase->chalan_no         = $request->chalan_no;
            $purchase->user_id           = Auth::id();
            $purchase->purchase_date     = $request->purchase_date;
            $purchase->payment_id        = $request->payment_id;
            $purchase->paid_amount       = $request->paid_amount;
            $purchase->total_qty         = $request->total_qty;
            $purchase->total_price       = $request->total_price;
            $purchase->due_amount        = $request->due_amount;
            $purchase->purchase_discount = $request->purchase_discount;
            $purchase->status            = $request->status;
            $purchase->payment_status    = $request->total_price == $request->paid_amount ? 'paid' : 'due';
            $purchase->vendor_id         = vendorId();
            $purchase->note              = $request->note;
            $purchase->save();

            //For product purchase details
            $product_ids = $request->product_id;
            foreach ( $product_ids as $key => $product_id ) {
                $purchaseDetails                      = new ProductPurchaseDetails();
                $purchaseDetails->product_purchase_id = $purchase->id;
                $purchaseDetails->product_id          = $product_id;
                $purchaseDetails->unit_id             = $request->unit_id[$key];
                $purchaseDetails->size_id             = $request->size_id[$key];
                $purchaseDetails->color_id            = $request->color_id[$key];
                $purchaseDetails->qty                 = $request->qty[$key];
                $purchaseDetails->rate                = $request->rate[$key];
                $purchaseDetails->sub_total           = $request->sub_total[$key];
                $purchaseDetails->save();
            }

            //For Product variant
            $variant = $request->all();
            ProductService::productVariants( $product_ids, $variant, $request->status );

            if ( $request->paid_amount > 0 ) {
                $purchase['partial_payment'] = 0;
                ProductPurchaseService::supplierPayment( $purchase );
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Product successfully purchase!',
            ] );

        }
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
            ProductVariant::updateOrCreate(
                [
                    'product_id' => $productDetail->product_id,
                    'unit_id'    => $productDetail->unit_id,
                    'size_id'    => $productDetail->size_id,
                    'color_id'   => $productDetail->color_id,
                ],
                [
                    'qty' => DB::raw( 'qty + ' . $productDetail->qty ),
                ]
            );

            // Update qty for the product
            $product = Product::find( $productDetail->product_id );
            if ( $product ) {
                $product->qty += $productDetail->qty; // Increase stock
                $product->save();
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
