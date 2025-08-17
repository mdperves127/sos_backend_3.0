<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Services\Vendor\VariantApiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductSaleController extends Controller
{
    public function create()
    {

        $product = Product::where('is_affiliate', 0)
        ->where('vendor_id', Auth::id())->where('status','received')
        ->when(request('category_id'), function ($q, $category) {
            $q->where('category_id', $category);
        })
        ->when(request('brand_id'), function ($q, $brand) {
            $q->where('brand_id', $brand);
        })
        ->when(request('search'), function ($q, $search) {
            $q->where(function ($query) use ($search) {
                $query->where('sku', $search)
                    ->orWhere('name', 'like', '%' . $search . '%');
            });
        })
        // ->with('productVariant',function($q){
        //     $q->select('id','product_id','unit_id','size_id','color_id','qty');
        // })
        ->select('id','category_id','brand_id','name','slug','sku','pre_order')
        ->get();


        return response()->json([
            'status' => 200,
            'data' => VariantApiService::variationApi(),
            'barcode' => barcode(10),
             'products' => $product,
        ]);
    }

    public function productSelect($slug)
    {
        $product = Product::where('slug',$slug)
                ->with('productVariant',function($q){
            $q->select('id','product_id','unit_id','size_id','color_id','qty')->with('product','color','size','unit');
        })
        ->select('id','category_id','brand_id','name','slug','sku',
        DB::raw('CASE
        WHEN discount_price IS NULL THEN selling_price
        ELSE discount_price
        END AS selling_price'))
        ->first();

        return response()->json([
            'status' => 200,
             'product' => $product,
        ]);

    }

    public function store(Request $request)
    {

    }
}
