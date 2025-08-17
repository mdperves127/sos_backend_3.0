<?php

namespace App\Service\Vendor;

use App\Models\ProductPurchase;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\Auth;

class ProductPurchaseService {

    public static function index() {
        $userId = Auth::id();

        $ProductPurchase = ProductPurchase::where( 'user_id', $userId )->latest()
            ->when( request( 'status' ) == 'ordered', function ( $q ) {
                return $q->where( 'status', 'ordered' );
            } )
            ->when( request( 'status' ) == 'received', function ( $q ) {
                return $q->where( 'status', 'received' );
            } )
            ->when( request( 'payment_status' ) == 'paid', function ( $q ) {
                return $q->where( 'payment_status', 'paid' );
            } )
            ->when( request( 'payment_status' ) == 'due', function ( $q ) {
                return $q->where( 'payment_status', 'due' );
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
                return $q->whereBetween( 'purchase_date', [$startDate, $endDate] );
            } )
            ->select( 'id', 'chalan_no', 'status', 'total_price', 'purchase_date', 'payment_status', 'due_amount', 'supplier_id', 'total_qty', 'return_qty' )

            ->with( ['supplier' => function ( $query ) {
                $query->select( 'id', 'supplier_name', 'business_name' );
            }] )
            ->with( 'purchaseDetails' )
            ->paginate( 10 )
            ->withQueryString();

        return $ProductPurchase;

    }

    public static function show( $id ) {
        $purchaseShow = ProductPurchase::whereId( $id )
            ->with( ['purchaseDetails' => function ( $query ) {
                $query->select( 'id', 'product_purchase_id', 'product_id', 'color_id', 'unit_id', 'size_id', 'qty', 'rate', 'sub_total' )->with( 'product', 'color', 'size', 'unit' );
            }] )
            ->with( ['supplier' => function ( $query ) {
                $query->select( 'id', 'supplier_name', 'phone', 'email', 'address' );
            }] )
            ->first();
        return $purchaseShow;
    }

    // public static function productVariants($product_ids , $variant ,$purchaseStatus)
    // {
    //     $variantsData = [];
    //     foreach($product_ids as $key => $product_id) {
    //         $variantsData[] = [
    //             // 'user_id' => Auth::id(),
    //             'product_id' => $product_id,
    //             'unit_id' => $variant['unit_id'][$key],
    //             'size_id' => $variant['size_id'][$key],
    //             'color_id' => $variant['color_id'][$key],
    //             'qty' => $purchaseStatus == "received" ? $variant['qty'][$key] : 0,
    //             'rate' => $variant['rate'][$key],
    //         ];
    //     }

    //     foreach ($variantsData as $variantData) {
    //         ProductVariant::updateOrCreate(
    //             [
    //                 // 'user_id' => $variantData['user_id'],
    //                 'product_id' => $variantData['product_id'],
    //                 'unit_id' => $variantData['unit_id'],
    //                 'size_id' => $variantData['size_id'],
    //                 'color_id' => $variantData['color_id'],
    //             ],
    //             // [
    //             //     'qty' => DB::raw('qty + ' . $variantData['qty']), // Increment the qty column
    //             //     // 'rate' => $variantData['rate'], // Update rate if needed
    //             // ]
    //         );

    //         // Update qty for the product
    //         $product = Product::find($product_id);
    //         if ($product) {
    //             $product->qty += $variantData['qty']; // Increase stock
    //             $product->save();
    //         }
    //     }

    // }

    public static function supplierPayment( $supplierPayment ) {

        // dd($supplierPayment);
        if ( $supplierPayment['partial_payment'] == 1 ) {
            $data = ProductPurchase::find( $supplierPayment['id'] );
            $data->decrement( 'due_amount', $supplierPayment['partial_payment_amount'] );
            if ( $data->due_amount == 0 ) {
                $data->payment_status = 'paid';
            }
            $data->save();
        }
        $payment                      = new SupplierPayment;
        $payment->user_id             = Auth::id();
        $payment->vendor_id           = Auth::id();
        $payment->supplier_id         = $supplierPayment['supplier_id'];
        $payment->product_purchase_id = $supplierPayment['id'];
        $payment->payment_method_id   = $supplierPayment['partial_payment'] == 1 ? $supplierPayment['payment_method'] : $supplierPayment['payment_id'];
        $payment->chalan_no           = $supplierPayment['chalan_no'];
        $payment->date                = $supplierPayment['purchase_date'];
        $payment->paid_amount         = $supplierPayment['partial_payment'] == 1 ? $supplierPayment['partial_payment_amount'] : $supplierPayment['paid_amount'];
        $payment->due_amount          = $supplierPayment['partial_payment'] == 0 ? $supplierPayment['due_amount'] : $supplierPayment['due_amount'] - $supplierPayment['partial_payment_amount'];
        $payment->vendor_id           = vendorId();
        $payment->save();

        return $payment;

    }

}
