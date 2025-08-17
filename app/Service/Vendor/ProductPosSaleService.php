<?php

namespace App\Service\Vendor;

use App\Models\CustomerPayment;
use App\Models\PosSales;
use App\Models\PosSalesDetails;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductPosSaleService {

    public static function index() {
        $vendorId = vendorId();

        $productSales = PosSales::where( 'vendor_id', $vendorId )
            ->when( request( 'payment_status' ) == 'paid', function ( $q ) {
                return $q->where( 'payment_status', 'paid' );
            } )
            ->when( request( 'payment_status' ) == 'due', function ( $q ) {
                return $q->where( 'payment_status', 'due' );
            } )
            ->when( request()->filled( 'search' ), function ( $query ) {
                $search = request()->input( 'search' );
                $query->where( function ( $q ) use ( $search ) {
                    $q->where( 'barcode', 'like', '%' . $search . '%' );
                } );
            } )
            ->when( request( 'start_date' ) && request( 'end_date' ), function ( $q ) {
                $startDate = request( 'start_date' );
                $endDate   = request( 'end_date' );
                return $q->whereBetween( 'sale_date', [$startDate, $endDate] );
            } )
            ->select( 'id', 'barcode', 'total_price', 'sale_date', 'payment_status', 'due_amount', 'customer_id', 'source_id', 'exchange_qty' )
            ->latest()
            ->with( ['customer' => function ( $query ) {
                $query->select( 'id', 'customer_name' );
            }] )
            ->with( 'saleDetails', 'source' )
            ->paginate( 10 )
            ->withQueryString();

        return $productSales;

    }

    public static function show( $id ) {
        $saleShow = PosSales::whereId( $id )
            ->with( ['saleDetails' => function ( $query ) {
                $query->select( 'id', 'pos_sales_id', 'product_id', 'color_id', 'unit_id', 'size_id', 'qty', 'rate', 'sub_total', 'status' )->with( 'product', 'color', 'size', 'unit' );
            }] )
            ->with( ['customer' => function ( $query ) {
                $query->select( 'id', 'customer_name', 'phone', 'email', 'address' );
            }] )
            ->with( ['user' => function ( $query ) {
                $query->select( 'id', 'name' );
            }] )
            ->select( 'id', 'customer_id', 'vendor_id', 'user_id', 'barcode', 'payment_id', 'sale_date', 'source_id', 'paid_amount', 'total_price', 'due_amount', 'sale_discount', 'total_qty', 'payment_status', 'change_amount' )
            ->first();
        return $saleShow;
    }

    public static function productSaleDetails( $product_ids, $saleId, $status ) {
        foreach ( $product_ids as $key => $product_id ) {
            $saleDetails               = new PosSalesDetails();
            $saleDetails->pos_sales_id = $saleId;
            $saleDetails->product_id   = $product_id;
            $saleDetails->unit_id      = request()->unit_id[$key];
            $saleDetails->size_id      = request()->size_id[$key];
            $saleDetails->color_id     = request()->color_id[$key];
            $saleDetails->qty          = request()->qty[$key];
            $saleDetails->rate         = request()->rate[$key];
            $saleDetails->sub_total    = request()->sub_total[$key];
            $saleDetails->status       = $status;
            $saleDetails->save();
        }

        return $saleDetails;
    }

    public static function productVariants( $product_ids, $variant ) {

        $variantsData = [];
        foreach ( $product_ids as $key => $product_id ) {
            $variantsData[] = [
                'user_id'    => vendorId(),
                'product_id' => $product_id,
                'unit_id'    => $variant['unit_id'][$key],
                'size_id'    => $variant['size_id'][$key],
                'color_id'   => $variant['color_id'][$key],
                'qty'        => $variant['qty'][$key],
                'rate'       => $variant['rate'][$key],
            ];
        }

        foreach ( $variantsData as $key => $variantData ) {
            ProductVariant::updateOrCreate(
                [
                    'user_id'    => vendorId(),
                    'product_id' => $variantData['product_id'],
                    'unit_id'    => $variantData['unit_id'],
                    'size_id'    => $variantData['size_id'],
                    'color_id'   => $variantData['color_id'],
                ],
                [
                    'qty' => DB::raw( 'qty - ' . $variantData['qty'] ), // Decrement the qty column
                    // 'rate' => $variantData['rate'], // Update rate if needed
                ]
            );

            // Update qty for the product
            $product = Product::find( $variantData['product_id'] );

            if ( $product ) {
                $product->qty -= $variantData['qty']; // Decrement stock
                $product->save();
            }
        }

    }

    public static function customerPayment( $customerPayment ) {

        //  dd($customerPayment);
        if ( $customerPayment['partial_payment'] == 1 ) {
            $data = PosSales::find( $customerPayment['id'] );
            $data->decrement( 'due_amount', $customerPayment['partial_payment_amount'] );
            if ( $data->due_amount == 0 ) {
                $data->payment_status = 'paid';
            }
            $data->save();
        }
        $payment                    = new CustomerPayment();
        $payment->user_id           = Auth::id();
        $payment->vendor_id         = Auth::id();
        $payment->customer_id       = $customerPayment['customer_id'];
        $payment->pos_sales_id      = $customerPayment['id'];
        $payment->payment_method_id = $customerPayment['partial_payment'] == 1 ? $customerPayment['payment_method'] : $customerPayment['payment_id'];
        $payment->invoice_no        = $customerPayment['barcode'];
        $payment->date              = $customerPayment['sale_date'];
        $payment->paid_amount       = $customerPayment['partial_payment'] == 1 ? $customerPayment['partial_payment_amount'] : $customerPayment['paid_amount'];
        $payment->due_amount        = $customerPayment['partial_payment'] == 0 ? $customerPayment['due_amount'] : $customerPayment['due_amount'] - $customerPayment['partial_payment_amount'];
        $payment->vendor_id         = vendorId();
        $payment->save();

        return $payment;

    }

    public static function productExchange( $product_ids, $variant ) {
        $variantsData = [];
        foreach ( $product_ids as $key => $product_id ) {
            $variantsData[] = [
                'product_id' => $product_id,
                'unit_id'    => $variant['unit_id'][$key],
                'size_id'    => $variant['size_id'][$key],
                'color_id'   => $variant['color_id'][$key],
                'qty'        => $variant['qty'][$key],
                'rate'       => $variant['rate'][$key],
            ];
        }

        foreach ( $variantsData as $variantData ) {
            ProductVariant::updateOrCreate(
                [
                    'product_id' => $variantData['product_id'],
                    'unit_id'    => $variantData['unit_id'],
                    'size_id'    => $variantData['size_id'],
                    'color_id'   => $variantData['color_id'],
                ],
                [
                    'qty' => DB::raw( 'qty - ' . $variantData['qty'] ), // Increment the qty column
                    // 'rate' => $variantData['rate'], // Update rate if needed
                ]
            );

            // Update qty for the product
            $product = Product::find( $product_id );
            if ( $product ) {
                $product->qty -= $variantData['qty']; // Increase stock
                $product->save();
            }
        }

    }

}
