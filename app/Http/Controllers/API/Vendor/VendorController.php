<?php

namespace App\Http\Controllers\API\Vendor;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Size;
use App\Models\User;
use App\Models\Subcategory;
use App\Models\ProductDetails;
use App\Models\specification;
use App\Rules\BrandRule;
use App\Rules\CategoryRule;
use App\Rules\SubCategorydRule;
use App\Service\Vendor\ProductService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Image;

class VendorController extends Controller
{
    public function AllCategory()
    {
        $category = Category::where('status', 'active')->with(['subcategory'])->get();
        return response()->json([
            'status' => 200,
            'category' => $category,
        ]);
    }

    public function AllSubCategory()
    {
        $subcategory = Subcategory::where('status', 'active')->get();
        return response()->json([
            'status' => 200,
            'subcategory' => $subcategory,
        ]);
    }


    // public function AllBrand()
    // {
    //   $brand= Brand::where('status','active')->get();
    //   return response()->json([
    //       'status'=>200,
    //       'brand'=>$brand,
    //   ]);
    // }


    public function AllColor()
    {
        $color = Color::all();
        return response()->json([
            'status' => 200,
            'color' => $color,
        ]);
    }

    public function AllSize()
    {
        $size = Size::all();
        return response()->json([
            'status' => 200,
            'size' => $size,
        ]);
    }

}
