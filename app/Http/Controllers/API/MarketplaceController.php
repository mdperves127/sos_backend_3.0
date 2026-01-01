<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MPCategory;
use App\Models\MPSubCategory;
use App\Models\MPBrand;

class MarketplaceController extends Controller
{
    public function categorySubcategoryBrand()
    {
        return response()->json([
            'status' => 200,
            'message' => 'Category, Subcategory & Brand fetched successfully',
            'data' => [
                'categories' => MPCategory::on('mysql')->get(),
                'subcategories' => MPSubCategory::on('mysql')->get(),
                'brands' => MPBrand::on('mysql')->get(),
            ]
        ]);
    }
}
