<?php

namespace App\Http\Controllers;

use App\Enums\Status;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Service\Vendor\ProductService;
use GPBMetadata\Google\Api\Auth;
use Illuminate\Http\Request;

class ApiTestController extends Controller
{
    //
    function index()
    {
    //   return  $product=Product::with('category','subcategory')->get();
    // $product =  Product::find(69);
    // $product->colors()->attach([11,12]);
    //     return "ok";
    }

}
