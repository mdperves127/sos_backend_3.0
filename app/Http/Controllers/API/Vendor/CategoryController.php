<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::where('status', 'active')->get();
        return response()->json([
            'status' => 200,
            'categories' => $categories
        ]);
    }
}
