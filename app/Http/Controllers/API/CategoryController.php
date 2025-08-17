<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Category;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CategoryController extends Controller
{

    public function CategoryIndex()
    {
        if(checkpermission('category') != 1){
            return $this->permissionmessage();
        }

        $category = Category::latest()->paginate(10);
        return response()->json([
            'status' => 200,
            'category' => $category,
        ]);
    }
    public function CategoryStore(Request $request)
    {

        $validator = Validator::make($request->all(), [

            'name' => 'required|unique:categories|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        } else {
            $category = new Category;
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $extension = $file->getClientOriginalExtension();
                $filename = time() . '.' . $extension;
                $file->move('uploads/category/', $filename);
                $category->image = 'uploads/category/' . $filename;
            }
            $category->name = $request->input('name');
            $category->slug = Str::slug($request->name);
            $category->description = $request->input('description');
            $category->meta_title = $request->input('meta_title');
            $category->meta_keywords = $request->input('meta_keywords');
            $category->meta_description = $request->input('meta_description');
            $category->status = $request->input('status');
            $category->tags = $request->input('tags');


            $category->save();
            return response()->json([
                'status' => 200,
                'message' => 'Category Added Sucessfully',
            ]);
        }
    }

    public function CategoryEdit($id)
    {
        $category = Category::find($id);
        if ($category) {
            return response()->json([
                'status' => 200,
                'category' => $category
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'No Category Id Found'
            ]);
        }
    }

    public function UpdateCategory(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|max:191',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages(),
            ]);
        } else {
            $category = Category::find($id);
            if ($category) {
                $category->meta_title = $request->input('meta_title');
                $category->meta_keywords = $request->input('meta_keywords');
                $category->meta_description = $request->input('meta_description');
                $category->name = $request->input('name');
                $category->slug = Str::slug($request->name);
                $category->description = $request->input('description');
                $category->status = $request->input('status');

                if ($request->hasFile('image')) {
                    $path = $category->image;
                    if (File::exists($path)) {
                        File::delete($path);
                    }
                    $file = $request->file('image');
                    $extension = $file->getClientOriginalExtension();
                    $filename = time() . '.' . $extension;
                    $file->move('uploads/category/', $filename);
                    $category->image = 'uploads/category/' . $filename;
                }


                $category->save();
                return response()->json([
                    'status' => 200,
                    'message' => 'Category Updated Successfully',
                ]);
            } else {
                return response()->json([
                    'status' => 404,
                    'message' => 'No Category ID Found',
                ]);
            }
        }
    }

    public function destroy($id)
    {
        $category = Category::find($id);
        if ($category) {
            $category->delete();
            return response()->json([
                'status' => 200,
                'message' => 'Category Deleted Successfully',
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'No Category ID Found',
            ]);
        }
    }

    function status(Request $request,$id){
        $validator =  Validator::make($request->all(), [
            'status' => 'required|in:active,pending'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages(),
            ]);
        }

        $category = Category::find($id);
        $category->status = $request->status;
        $category->save();

        return response()->json([
            'status'=>200,
            'message'=>'Category updated successfully!'
        ]);

    }
}
