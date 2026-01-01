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
                'categories' => Category::on('mysql')->all(),
                'subcategories' => Subcategory::on('mysql')->all(),
                'brands' => Brand::on('mysql')->all(),
            ]
        ]);
    }
}
