<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubCategoryController extends Controller {
    public function index() {
        $subcategory = Subcategory::with( 'category' )->latest()->paginate( 10 );
        return response()->json( [
            'status'      => 200,
            'subcategory' => $subcategory,
        ] );
    }

    public function store( Request $request ) {

        $validator = Validator::make( $request->all(), [

            'name'        => 'required|max:255',
            'category_id' => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        } else {
            $subcategory              = new Subcategory;
            $subcategory->category_id = $request->input( 'category_id' );
            $subcategory->name        = $request->input( 'name' );
            $subcategory->slug        = slugCreate( Subcategory::class, $request->name );
            $subcategory->status      = $request->input( 'status' );
            $subcategory->save();
            return response()->json( [
                'status'  => 200,
                'message' => 'SubCategory Added Successfully',
            ] );
        }
    }

    public function edit( $id ) {
        $subcategory = Subcategory::find( $id );
        if ( $subcategory ) {
            return response()->json( [
                'status'      => 200,
                'subcategory' => $subcategory,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Subcategory Id Found',
            ] );
        }
    }

    public function update( Request $request, $id ) {
        $validator = Validator::make( $request->all(), [
            'name'        => 'required|max:191',
            'category_id' => 'required|max:191',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        } else {
            $subcategory = Subcategory::find( $id );
            if ( $subcategory ) {

                $subcategory->name        = $request->input( 'name' );
                $subcategory->slug        = slugUpdate( Subcategory::class, $request->name, $id );
                $subcategory->category_id = $request->input( 'category_id' );
                $subcategory->status      = $request->input( 'status' );
                $subcategory->save();
                return response()->json( [
                    'status'  => 200,
                    'message' => 'Sub Category Updated Successfully',
                ] );
            } else {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'No Sub Category Found',
                ] );
            }
        }
    }

    public function destroy( $id ) {
        $subcategory = Subcategory::find( $id );
        if ( $subcategory ) {
            $subcategory->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'SubCategory Deleted Successfully',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No SubCategory Found',
            ] );
        }
    }

    function status( Request $request, $id ) {

        $validator = Validator::make( $request->all(), [
            'status' => 'required|in:active,pending',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 422,
                'errors' => $validator->messages(),
            ] );
        }

        $subcategory         = Subcategory::find( $id );
        $subcategory->status = $request->status;
        $subcategory->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Subcategory updated successfully!',
        ] );
    }
}
