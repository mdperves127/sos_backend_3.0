<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;

class MerchantFrontendController extends Controller
{
    public function products()
    {
        $products = Product::all();
        return response()->json($products);
    }
    public function product($id)
    {
        $product = Product::with('category', 'subcategory', 'brand', 'productImage', 'productdetails', 'vendor')->find($id);
        return response()->json(compact('product'));
    }
}
