<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ProductPurchase;
use App\Models\SupplierReturnProduct;
use App\Models\ProductVariant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;


class SupplierProductReturnController extends Controller
{
    public function returnToSupplier(Request $request , $id)
    {

        $purchase = ProductPurchase::find($id);
        if (!$purchase) {
            return response()->json([
                'status' => 404,
                'message' => 'Product invoice not found.',
            ]);
        }

        if ($purchase->status != 'received') {
            return response()->json([
                'status' => 400,
                'message' => 'Product not received yet.',
            ]);
        }

        foreach($purchase->purchaseDetails as $key => $purchaseDetail) {
            // Check if the return quantity array has the key and the return quantity is not null or 0
            if (isset($request->return_qty[$key]) && ($request->return_qty[$key] !== null && $request->return_qty[$key] != 0)) {
                // Check if return qty is greater than 0 and less than or equal to purchased qty
                if ($request->return_qty[$key] > 0 && $request->return_qty[$key] <= $purchaseDetail->qty) {
                    // Calculate the returned sub total
                    $returnedSubTotal = $request->return_qty[$key] * $purchaseDetail->rate;

                    // Update the sub total of the purchase detail
                    $purchaseDetail->sub_total -= $returnedSubTotal;
                    $purchaseDetail->qty -= $request->return_qty[$key]; // Decrement the purchased quantity
                    $purchaseDetail->save();

                    // Store the return product
                    $returnProduct = new SupplierReturnProduct();
                    $returnProduct->product_purchase_id = $purchase->id;
                    $returnProduct->product_id = $purchaseDetail->product_id;
                    $returnProduct->r_unit_id = $purchaseDetail->unit_id;
                    $returnProduct->r_size_id = $purchaseDetail->size_id;
                    $returnProduct->r_color_id = $purchaseDetail->color_id;
                    $returnProduct->r_purchase_qty = $purchaseDetail->qty;
                    $returnProduct->r_rate = $purchaseDetail->rate;
                    $returnProduct->r_sub_total = $returnedSubTotal; // Store the returned sub total
                    $returnProduct->remark = $request->remark[$key];
                    $returnProduct->return_qty = $request->return_qty[$key];
                    $returnProduct->save();

                    // Update qty for the product variant
                    ProductVariant::updateOrCreate(
                        [
                            'product_id' => $purchaseDetail->product_id,
                            'unit_id' => $purchaseDetail->unit_id,
                            'size_id' => $purchaseDetail->size_id,
                            'color_id' => $purchaseDetail->color_id,
                        ],
                        [
                            'qty' => DB::raw('qty - ' . $request->return_qty[$key]),
                        ]
                    );

                    // Update qty for the product
                    $product = Product::find($purchaseDetail->product_id);
                    if ($product) {
                        $product->qty -= $request->return_qty[$key]; // Decrease stock
                        $product->save();
                    }


                    // Store return qty for the product
                    if ($purchase) {
                        $purchase->return_qty += $request->return_qty[$key]; // Return qty store
                        $purchase->return_date = date('Y-m-d');// Return date store
                        $purchase->return_amount = $returnedSubTotal;// Return date store
                        $purchase->save();
                    }

                }else {
                    return response()->json([
                        'status' => 400,
                        'message' => 'Product return quantity is invalid !',
                    ]);
                }
            }
        }

        return response()->json([
            'status' => 200,
            'message' => 'Product successfully returned to supplier.',
        ]);
    }

    function returnList()
    {
        $returnProducts = ProductPurchase::where('return_qty','>' , 0)
        ->with(['supplier' => function($query) {
            $query->select('id', 'supplier_name');
        }])
        ->select('id','chalan_no','purchase_date','return_date','return_qty','return_amount','supplier_id')
        ->get();
        return response()->json([
            'status' => 200,
            'return_list' => $returnProducts,
        ]);
    }

    function returnListDetails($id)
    {
        $returnProduct = ProductPurchase::whereId($id)->where('return_qty','>' , 0)
        ->with(['returnDetails' => function($query) {
            $query->select('id', 'product_purchase_id','product_id','r_color_id','r_unit_id','r_size_id','return_qty','r_rate','r_sub_total','created_at')->with('product','color','size','unit');
        }])
        ->select('id','supplier_id','chalan_no','purchase_date','return_qty','return_amount','return_date')
        ->first();

        return response()->json([
            'status' => 200,
            'return_list' => $returnProduct,
        ]);
    }
}
