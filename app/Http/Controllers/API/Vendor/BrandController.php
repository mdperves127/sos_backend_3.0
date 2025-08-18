<?php

namespace App\Http\Controllers\API\Vendor;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

class BrandController extends Controller {
    //

    public function create( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'name'   => 'required|unique:brands|max:255',
            'status' => 'required|in:' . Status::Active->value . ',' . Status::Pending->value,
            'image'  => 'nullable|image|mimes:jpeg,png,jpg,gif',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        }

        $validateData = $validator->validated();

        $slug = slugCreate( Brand::class, $validateData['name'] );

        $image = '';
        if ( $request->hasFile( 'image' ) ) {
            $image = fileUpload( $validateData['image'], 'uploads/brand' );
        }

        Brand::create( [
            'name'       => $validateData['name'],
            'slug'       => $slug,
            'status'     => $validateData['status'],
            'image'      => $image,
            'user_id'    => auth()->id(),
            'created_by' => Status::Vendor->value,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Brand Added Successfully',
        ] );
    }

    function allBrand() {
        $brands = Brand::latest()->paginate( 15 );
        return response()->json( [
            'status' => 200,
            'brands' => $brands,
        ] );
    }

    function allBrandActive() {

        $brands = Brand::where( 'status', 'active' )
            ->latest()
            ->get();

        return response()->json( [
            'status' => 200,
            'brands' => $brands,
        ] );
    }

    function delete( $id ) {
        $brand = Brand::where( ['user_id' => auth()->user()->id, 'id' => $id] )->firstOrFail();

        if ( $brand ) {
            $brand->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'Brand deleted Successfully',
            ] );
        }
    }
    function edit( $id ) {
        $brand = Brand::where( ['user_id' => auth()->user()->id, 'id' => $id] )->firstOrFail();
        return response()->json( [
            'status'  => 200,
            'message' => $brand,
        ] );
    }
    public function update( Request $request, $id ) {
        $validator = Validator::make( $request->all(), [
            'name'   => 'required|max:255|unique:brands,name,' . $id . ',id',
            'status' => 'required|in:active,pending',
            'image'  => 'nullable|image|mimes:jpeg,png,jpg,gif',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        }

        $brand = Brand::where( [
            'user_id' => auth()->id(),
            'id'      => $id,
        ] )->first();

        if ( $brand ) {
            $brand->name   = $request->input( 'name' );
            $brand->slug   = slugUpdate( Brand::class, $request->input( 'name' ), $brand->id );
            $brand->status = $request->input( 'status' );

            if ( $request->hasFile( 'image' ) ) {
                if ( File::exists( $brand->image ) ) {
                    File::delete( $brand->image );
                }

                $file      = $request->file( 'image' );
                $extension = $file->getClientOriginalExtension();
                $filename  = time() . '.' . $extension;
                $file->move( 'uploads/brand/', $filename );
                $brand->image = 'uploads/brand/' . $filename;
            }

            $brand->save();

            return response()->json( [
                'status'  => 200,
                'message' => 'Brand updated successfully',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Brand ID Found',
            ] );
        }
    }

}
