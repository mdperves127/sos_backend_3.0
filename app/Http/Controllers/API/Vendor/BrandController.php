<?php

namespace App\Http\Controllers\API\Vendor;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\BrandRequest;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class BrandController extends Controller
{
    //
    function create(BrandRequest $request)
    {
        $validateData = $request->validated();
        $slug = slugCreate(Brand::class, $validateData['name']);

        if ($request->hasFile('image')) {
            $image = fileUpload($validateData['image'], 'uploads/brand');
        } else {
            $image = '';
        }

        Brand::create([
            'name' => $validateData['name'],
            'slug' => $slug,
            'status' => Status::Active->value,
            'image' => $image,
            'user_id' => auth()->user()->id,
            'created_by' => Status::Vendor->value
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Brand Added Sucessfully',
        ]);
    }

    function allBrand()
    {

        $brands = Brand::where('status','active')
            ->latest()
            ->paginate(15);

        return response()->json([
            'status' => 200,
            'brands' => $brands
        ]);
    }

    function allBrandActive()
    {

        $brands = Brand::where('status','active')
            ->latest()
            ->get();

        return response()->json([
            'status' => 200,
            'brands' => $brands
        ]);
    }

    function delete($id)
    {
        $brand = Brand::where(['user_id'=>auth()->user()->id,'id'=>$id])->firstOrFail();

        if ($brand) {
            $brand->delete();
            return response()->json([
                'status' => 200,
                'message' => 'Brand deleted Sucessfully',
            ]);
        }
    }
    function edit($id)
    {
        $brand = Brand::where(['user_id'=>auth()->user()->id,'id'=>$id])->firstOrFail();
        return response()->json([
            'status' => 200,
            'message' => $brand
        ]);
    }

    function update(Request $request, $id)
    {

        $request->validate([
            'name'=>'required|unique:brands,name,'.$id,
            'image'=>'nullable|image|mimes:jpeg,png,jpg,gif'
        ]);

        $brand = Brand::where(['user_id'=>auth()->user()->id,'id'=>$id])->firstOrFail();

        $brand->name = $validateData['name'];

        if ($validateData['image']) {

            if (File::exists($brand->image)) {
                File::delete($brand->image);
            }
            $image = fileUpload($validateData['image'], 'uploads/brand');
            $brand->image = $image;
        } else {
            $brand->image = null;
        }


        $brand->slug = slugUpdate(Brand::class, $validateData['name'], $brand->id);

        $brand->save();

        return response()->json([
            'status' => 200,
            'message' => 'Brand updated Sucessfully',
        ]);
    }


}
