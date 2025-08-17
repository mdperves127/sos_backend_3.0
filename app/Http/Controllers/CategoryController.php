<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class CategoryController extends Controller
{

    public function Store(Request $request)
    {
        $category = new Category();
        $category->name = $request->name;
        $category->slug =Str::slug($request->name);
        $category->status = $request->status;
        $category->save();
        return back();
    }
}
