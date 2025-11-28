<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DropshipperFrontendController extends Controller
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
