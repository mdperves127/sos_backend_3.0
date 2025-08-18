<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SupplierController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return response()->json( [
            'status'   => 200,
            'supplies' => Supplier::where( 'vendor_id', vendorId() )->latest()->get(),
        ] );
    }

    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function store( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'supplier_name' => 'required',
            'business_name' => 'required|unique:suppliers,business_name,NULL,id,vendor_id,' . vendorId(),
            'phone'         => 'required|unique:suppliers,phone,NULL,id,vendor_id,' . vendorId(),
            'email'         => 'nullable|unique:suppliers,email,NULL,id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        }

        Supplier::create( [
            'user_id'       => Auth::id(),
            'supplier_name' => $request->supplier_name,
            'supplier_slug' => Str::slug( $request->supplier_name ),
            'supplier_id'   => generateRandomString( 8 ),
            'business_name' => $request->business_name,
            'phone'         => $request->phone,
            'email'         => $request->email,
            'address'       => $request->address,
            'description'   => $request->description,
            'status'        => $request->status,
            'vendor_id'     => vendorId(),
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Supplier Added Successfully!',
        ] );

    }

    /**
     * Display the resource.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function edit( $id ) {
        return response()->json( [
            'status'  => 200,
            'message' => Supplier::find( $id ),
        ] );
    }

    /**
     * Update the resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function update( Request $request, $id ) {
        $validator = Validator::make( $request->all(), [
            'supplier_name' => 'required',
            'business_name' => 'required|unique:suppliers,business_name,' . $id . ',id,vendor_id,' . vendorId(),
            'phone'         => 'required|unique:suppliers,phone,' . $id . ',id,vendor_id,' . vendorId(),
            'email'         => 'nullable|unique:suppliers,email,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        }

        $supplier = Supplier::find( $id );
        if ( !$supplier ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Supplier not found',
            ], 404 );
        }

        $supplier->update( [
            'supplier_name' => $request->supplier_name,
            'supplier_slug' => Str::slug( $request->supplier_name ),
            'business_name' => $request->business_name,
            'phone'         => $request->phone,
            'email'         => $request->email,
            'address'       => $request->address,
            'description'   => $request->description,
            'status'        => $request->status,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Supplier Updated Successfully!',
        ] );

    }

    /**
     * Remove the resource from storage.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        Supplier::find( $id )->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Supplier Deleted Successfully !',
        ] );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        $data         = Supplier::find( $id );
        $data->status = $data->status == 'active' ? 'deactive' : 'active';
        $data->save();

        if ( $data->status == 'active' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Supplier Active Successfully !',
            ] );
        } else {
            return response()->json( [
                'status'  => 200,
                'message' => 'Supplier Deactive Successfully !',
            ] );
        }

    }
}
