<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\MPSubCategory;
use App\Models\MPCategory;
use Illuminate\Support\Str;

class SubCategoryController extends Controller
{
    public function SubCategoryIndex()
    {
        if(checkpermission('sub-category') != 1){
            return $this->permissionmessage();
        }

        $subcategory = MPSubCategory::with('category')->latest()->paginate(10);
        return response()->json([
            'status' => 200,
            'subcategory' => $subcategory,
        ]);
    }

    public function SubCategoryStore(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255|unique:m_p_sub_categories',
            'category_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        } else {
            $subcategory = new MPSubCategory;
            $subcategory->category_id = $request->input('category_id');
            $subcategory->name = $request->input('name');
            $subcategory->slug = slugCreate(MPSubCategory::class, $request->name);
            $subcategory->status = $request->input('status');
            $subcategory->save();
            return response()->json([
                'status' => 200,
                'message' => 'SubCategory Added Sucessfully',
            ]);
        }
    }

    public function SubCategoryEdit($id)
    {
        $subcategory = MPSubCategory::find($id);
        if ($subcategory) {
            return response()->json([
                'status' => 200,
                'subcategory' => $subcategory
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'No Subcategory Id Found'
            ]);
        }
    }

    public function UpdateSubCategory(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|max:191|unique:m_p_sub_categories,name,'.$id,
            'category_id' => 'required|max:191',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages(),
            ]);
        } else {
            $subcategory = MPSubCategory::find($id);
            if ($subcategory) {

                $subcategory->name = $request->input('name');
                $subcategory->slug = slugUpdate(MPSubCategory::class, $request->name, $id);
                $subcategory->category_id = $request->input('category_id');
                $subcategory->status = $request->input('status');
                $subcategory->save();
                return response()->json([
                    'status' => 200,
                    'message' => 'Sub Category Updated Successfully',
                ]);
            } else {
                return response()->json([
                    'status' => 404,
                    'message' => 'No Sub Category ID Found',
                ]);
            }
        }
    }

    public function destroy($id)
    {
        $subcategory = MPSubCategory::find($id);
        if ($subcategory) {
            $subcategory->delete();
            return response()->json([
                'status' => 200,
                'message' => 'SubCategory Deleted Successfully',
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'No SubCategory ID Found',
            ]);
        }
    }

    function status(Request $request, $id)
    {

        $validator =  Validator::make($request->all(), [
            'status' => 'required|in:active,pending'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages(),
            ]);
        }

        $subcategory = MPSubCategory::find($id);
        $subcategory->status = $request->status;
        $subcategory->save();

        return response()->json([
            'status'=>200,
            'message'=>'Subcategory updated successfully!'
        ]);
    }
}
