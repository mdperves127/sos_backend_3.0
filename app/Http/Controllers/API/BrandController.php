<?php

namespace App\Http\Controllers\API;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Brand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function BrandIndex()
    {
        if(checkpermission('brand') != 1){
            return $this->permissionmessage();
        }
        $brand = Brand::where('created_by',Status::Admin->value)
        ->when(request('search'),fn($q, $name)=>$q->where('name','like',"%{$name}%"))
        ->latest()->paginate(12);

        return $this->response($brand);
    }

    function BrandActive(){
        $brand = Brand::where('created_by',Status::Admin->value)
        ->latest()->get();

        return response()->json([
            'status' => 200,
            'brand' => $brand,
        ]);
    }

    public function BrandStore(Request $request)
    {


        $validator = Validator::make($request->all(), [

            'name' => 'required|unique:brands|max:255',

        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'errors' => $validator->messages(),
            ]);
        } else {
            $brand = new Brand;
            $brand->name = $request->input('name');
            $brand->slug = Str::slug($request->name);
            $brand->status = $request->input('status');
            $brand->user_id = auth()->user()->id;
            $brand->created_by = Status::Admin->value;

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $extension = $file->getClientOriginalExtension();
                $filename = time() . '.' . $extension;
                $file->move('uploads/brand/', $filename);
                $brand->image = 'uploads/brand/' . $filename;
            }

            $brand->save();
            return response()->json([
                'status' => 200,
                'message' => 'Brand Added Sucessfully',
            ]);
        }
    }

    public function BrandEdit($id)
    {
        $category = Brand::find($id);
        if ($category) {
            return response()->json([
                'status' => 200,
                'category' => $category
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'No Brand Id Found'
            ]);
        }
    }

    public function BrandUpdate(Request $request, $id)
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
            $brand = Brand::find($id);
            if ($brand) {


                $brand->name = $request->input('name');
                $brand->slug = slugCreate(Brand::class,$request->name);
                $brand->status = $request->input('status');


                if (request()->hasFile('image')) {
                    info(request()->all());
                    $path = $brand->image;
                    if (File::exists($path)) {
                        File::delete($path);
                    }
                    $file = request('image');
                    $extension = $file->getClientOriginalExtension();
                    $filename = time() . '.' . $extension;
                    $file->move('uploads/brand/', $filename);
                    $brand->image = 'uploads/brand/' . $filename;
                }


                $brand->update();

                return response()->json([
                    'status' => 200,
                    'message' => 'Brand Updated Successfully',
                ]);
            } else {
                return response()->json([
                    'status' => 404,
                    'message' => 'Brand Not Found',
                ]);
            }
        }
    }

    public function destroy($id)
    {
        $category = Brand::find($id);


        $image_path = app_path("uploads/brand/{$category->image}");

        if (File::exists($image_path)) {
            unlink($image_path);
        }


        if ($category) {
            $category->delete();
            return response()->json([
                'status' => 200,
                'message' => 'Brand Deleted Successfully',
            ]);
        } else {
            return response()->json([
                'status' => 404,
                'message' => 'No Brand ID Found',
            ]);
        }
    }
}
