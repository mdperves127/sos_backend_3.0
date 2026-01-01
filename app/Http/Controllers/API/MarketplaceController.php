<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;

class MarketplaceController extends Controller
{
    public function categorySubcategoryBrand()
    {
        return response()->json([
            'status' => 200,
            'message' => 'Category, Subcategory & Brand fetched successfully',
            'data' => [
                'categories' => Category::on('mysql')->get(),
                'subcategories' => Subcategory::on('mysql')->get(),
                'brands' => Brand::on('mysql')->get(),
            ]
        ]);
    }
}
