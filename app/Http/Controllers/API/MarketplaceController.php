<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MPCategory;
use App\Models\MPSubCategory;
use App\Models\MPBrand;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;

class MarketplaceController extends Controller
{
    public function categorySubcategoryBrand()
    {
        if(tenant()->type == 'merchant'){
            $categories = Category::latest()->get();
            $subcategories = Subcategory::latest()->get();
            $brands = Brand::latest()->get();
        }else{
            $categories = MPCategory::on('mysql')->get();
            $subcategories = MPSubCategory::on('mysql')->get();
            $brands = MPBrand::on('mysql')->get();
        }
        return response()->json([
            'status' => 200,
            'message' => 'Category, Subcategory & Brand fetched successfully',
            'data' => [
            'categories' => $categories,
            'subcategories' => $subcategories,
            'brands' => $brands,
            ]
        ]);
    }
}
