<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\PosSaleReturn;
use App\Models\PosSales;
use App\Models\PosSaleWastageReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosSaleReturnController extends Controller {

    //GENERAL RETURN
    public function returnPosSaleProduct( Request $request, $id ) {
        $sale = PosSales::find( $id );
        if ( !$sale ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product invoice not found.',
            ] );
        }

        foreach ( $sale->saleDetails as $key => $saleDetail ) {
            // Check if the return quantity array has the key and the return quantity is not null or 0
            if ( isset( $request->return_qty[$key] ) && ( $request->return_qty[$key] !== null && $request->return_qty[$key] != 0 ) ) {
                // Check if return qty is greater than 0 and less than or equal to sale qty
                if ( $request->return_qty[$key] > 0 && $request->return_qty[$key] <= $saleDetail->qty ) {
                    // Calculate the returned sub total
                    $returnedSubTotal = $request->return_qty[$key] * $saleDetail->rate;

                    //Update the sub total of the sale detail

                    $saleDetail->sub_total -= $returnedSubTotal;
                    $saleDetail->qty -= $request->return_qty[$key]; // Decrement the sale quantity
                    $saleDetail->save();

                    // Store the return product
                    $returnProduct               = new PosSaleReturn();
                    $returnProduct->pos_sales_id = $sale->id;
                    $returnProduct->product_id   = $saleDetail->product_id;
                    $returnProduct->unit_id      = $saleDetail->unit_id;
                    $returnProduct->size_id      = $saleDetail->size_id;
                    $returnProduct->color_id     = $saleDetail->color_id;
                    $returnProduct->sale_qty     = $saleDetail->qty;
                    $returnProduct->rate         = $saleDetail->rate;
                    $returnProduct->sub_total    = $returnedSubTotal; // Store the returned sub total
                    $returnProduct->remark       = $request->remark[$key];
                    $returnProduct->return_qty   = $request->return_qty[$key];
                    $returnProduct->save();

                    // Update qty for the product variant
                    ProductVariant::updateOrCreate(
                        [
                            'product_id' => $saleDetail->product_id,
                            'unit_id'    => $saleDetail->unit_id,
                            'size_id'    => $saleDetail->size_id,
                            'color_id'   => $saleDetail->color_id,
                        ],
                        [
                            'qty' => DB::raw( 'qty + ' . $request->return_qty[$key] ),
                        ]
                    );

                    // Update qty for the product
                    $product = Product::find( $saleDetail->product_id );
                    if ( $product ) {
                        $product->qty += $request->return_qty[$key]; // Increase stock
                        $product->save();
                    }

                    // Store return qty for the product
                    if ( $sale ) {
                        $sale->return_qty += $request->return_qty[$key];
                        $sale->return_date   = date( 'Y-m-d' );
                        $sale->return_amount = $returnedSubTotal;
                        if ( $sale->total_price - $returnedSubTotal < 0 ) {
                            $sale->total_price = 0;
                        } else {
                            $sale->total_price -= $returnedSubTotal;
                        }
                        $sale->save();
                    }

                } else {
                    return response()->json( [
                        'status'  => 400,
                        'message' => 'Product return quantity is invalid !',
                    ] );
                }
            }
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Product successfully returned !.',
        ] );
    }

    function returnList() {
        $returnProducts = PosSales::where( 'return_qty', '>', 0 )
            ->with( ['customer' => function ( $query ) {
                $query->select( 'id', 'customer_name' );
            }] )
            ->select( 'id', 'barcode', 'sale_date', 'return_date', 'return_qty', 'return_amount', 'customer_id' )
            ->get();
        return response()->json( [
            'status'      => 200,
            'return_list' => $returnProducts,
        ] );
    }

    function returnListDetails( $id ) {
        $returnProduct = PosSales::whereId( $id )->where( 'return_qty', '>', 0 )
            ->with( ['returnDetails' => function ( $query ) {
                $query->select( 'id', 'pos_sales_id', 'product_id', 'color_id', 'unit_id', 'size_id', 'return_qty', 'rate', 'sub_total' )->with( 'product', 'color', 'size', 'unit' );
            }] )
            ->select( 'id', 'customer_id', 'barcode', 'sale_date', 'return_qty', 'return_amount', 'return_date', 'created_at' )
            ->first();

        return response()->json( [
            'status'      => 200,
            'return_list' => $returnProduct,
        ] );
    }

    // WASTAGE RETURN

    public function getInvoice( Request $request ) {
        $sale = PosSales::where( 'barcode', $request->invoice_no )
            ->with( 'saleDetails', function ( $query ) {
                $query->select( 'id', 'qty', 'pos_sales_id', 'product_id', 'color_id', 'unit_id', 'size_id', 'rate', 'sub_total' )->with( 'product', 'color', 'size', 'unit' );
            } )->first();
        if ( !$sale ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Invoice not found.',
            ] );
        }

        return response()->json( [
            'status' => 404,
            'sales'  => $sale,
        ] );

    }

    public function returnPosSaleWastageProduct( Request $request ) {
        $sale = PosSales::where( 'id', $request->id )
            ->with( 'saleDetails', function ( $q ) {
                $q->select( '*' );
            } )->first();

        if ( !$sale ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product invoice not found.',
            ] );
        }

        foreach ( $sale->saleDetails as $key => $saleDetail ) {
            // Check if the return quantity array has the key and the return quantity is not null or 0
            if ( isset( $request->return_qty[$key] ) && ( $request->return_qty[$key] !== null && $request->return_qty[$key] != 0 ) ) {
                // Check if return qty is greater than 0 and less than or equal to purchased qty
                if ( $request->return_qty[$key] > 0 && $request->return_qty[$key] <= $saleDetail->qty ) {
                    // Calculate the returned sub total
                    $returnedSubTotal = $request->return_qty[$key] * $saleDetail->rate;

                    // Update the sub total of the purchase detail
                    $saleDetail->sub_total -= $returnedSubTotal;
                    $saleDetail->qty -= $request->return_qty[$key]; // Decrement the purchased quantity
                    $saleDetail->save();

                    // Store the return product
                    $returnProduct               = new PosSaleWastageReturn();
                    $returnProduct->pos_sales_id = $sale->id;
                    $returnProduct->product_id   = $saleDetail->product_id;
                    $returnProduct->unit_id      = $saleDetail->unit_id;
                    $returnProduct->size_id      = $saleDetail->size_id;
                    $returnProduct->color_id     = $saleDetail->color_id;
                    $returnProduct->sale_qty     = $saleDetail->qty;
                    $returnProduct->rate         = $saleDetail->rate;
                    $returnProduct->sub_total    = $returnedSubTotal; // Store the returned sub total
                    $returnProduct->remark       = $request->remark[$key];
                    $returnProduct->return_qty   = $request->return_qty[$key];
                    $returnProduct->save();

                    // PosSalesDetails::updateOrCreate(
                    //     [
                    //         'pos_sales_id' => $sale->id,
                    //         'product_id' => $saleDetail->product_id,
                    //         'unit_id' => $saleDetail->unit_id,
                    //         'size_id' => $saleDetail->size_id,
                    //         'color_id' => $saleDetail->color_id,
                    //     ],
                    //     [
                    //         'qty' => DB::raw('qty - ' . $request->return_qty[$key]),
                    //     ]
                    // );

                    // Store return qty for the product
                    if ( $sale ) {
                        $sale->wastage_qty += $request->return_qty[$key]; // Return qty store
                        $sale->wastage_date   = date( 'Y-m-d' ); // Return date store
                        $sale->wastage_amount = $returnedSubTotal; // Return date store
                        $sale->save();
                    }

                } else {
                    return response()->json( [
                        'status'  => 400,
                        'message' => 'Product return quantity is invalid !',
                    ] );
                }
            }
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Product successfully returned !.',
        ] );
    }

    public function wastageReturnList() {
        $wastageProduct = PosSales::where( 'wastage_qty', '>', 0 )
            ->with( ['customer' => function ( $query ) {
                $query->select( 'id', 'customer_name' );
            }] )
            ->select( 'id', 'barcode', 'sale_date', 'wastage_date', 'wastage_qty', 'wastage_amount', 'customer_id' )
            ->latest()
            ->get();
        return response()->json( [
            'status'      => 200,
            'return_list' => $wastageProduct,
        ] );
    }

    function wastageListDetails( $id ) {
        $wastageProduct = PosSales::whereId( $id )->where( 'wastage_qty', '>', 0 )
            ->with( ['wastageDetails' => function ( $query ) {
                $query->select( 'id', 'pos_sales_id', 'product_id', 'color_id', 'unit_id', 'size_id', 'return_qty', 'rate', 'sub_total' )->with( 'product', 'color', 'size', 'unit' );
            }] )
            ->select( 'id', 'customer_id', 'barcode', 'sale_date', 'wastage_qty', 'wastage_amount', 'wastage_date', 'created_at' )
            ->first();

        return response()->json( [
            'status'      => 200,
            'return_list' => $wastageProduct,
        ] );
    }

}
