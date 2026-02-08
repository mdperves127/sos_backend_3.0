<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NCategory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;

class NcategoryController extends Controller
{
    public function index()
    {
        $categories = NCategory::all();
        return response()->json([
            'status' => 200,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:n_categories|max:255',
            'status' => 'required|in:active,inactive',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if($validator->fails()){
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        }

        if($request->hasFile('image')){
            $image = $request->file('image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->move(public_path('uploads/news/categories'), $imageName);
            $imagePath = 'uploads/news/categories/' . $imageName;
        }
        else{
            $imagePath = null;
        }

        $category = NCategory::create([
            'name' => $request->name,
            'slug' => slugCreate(NCategory::class, $request->name),
            'status' => $request->status,
            'image' => $imagePath,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Category created successfully',
            'category' => $category,
        ]);
    }

    public function edit($id)
    {
        $category = NCategory::find($id);
        return response()->json([
            'status' => 200,
            'category' => $category,
        ]);
    }
    public function update(Request $request, $id)
    {
        $category = NCategory::find($id);

        if($category){
            return response()->json([
                'status' => 400,
                'message' => 'Category not found',
            ]);
        }

        if($request->hasFile('image')){
            $image = $request->file('image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->move(public_path('uploads/news/categories'), $imageName);
            $imagePath = 'uploads/news/categories/' . $imageName;
        }
        else{
            $imagePath = $category->image;
        }
        $category->update([
            'name' => $request->name,
            'slug' => slugUpdate(NCategory::class, $request->name, $id),
            'status' => $request->status,
            'image' => $imagePath,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Category updated successfully',
            'category' => $category,
        ]);
    }
    public function destroy($id)
    {
        $category = NCategory::find($id);
        if($category){
            return response()->json([
                'status' => 400,
                'message' => 'Category not found',
            ]);
        }
        if($category->image){
            $imagePath = $category->image;
            if(File::exists($imagePath)){
                File::delete($imagePath);
            }
        }
        $category->delete();
        return response()->json([
            'status' => 200,
            'message' => 'Category deleted successfully',
        ]);
    }
}
