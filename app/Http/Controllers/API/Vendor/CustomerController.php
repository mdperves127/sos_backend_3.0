<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return response()->json( [
            'status'    => 200,
            'customers' => Customer::latest()->where( 'vendor_id', vendorId() )->get(),
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
        $currentUserId = vendorId();

        $validator = Validator::make( $request->all(), [
            'customer_name' => 'required',
            'phone'         => 'required|unique:customers,phone,NULL,id,vendor_id,' . vendorId(),
            'email'         => 'nullable|unique:customers,email,NULL,id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        }

        $customer = Customer::create( [
            'user_id'       => Auth::id(),
            'customer_name' => $request->customer_name,
            'customer_slug' => Str::slug( $request->customer_name ),
            'customer_id'   => generateRandomString( 8 ),
            'customer_type' => $request->customer_type,
            'phone'         => $request->phone,
            'email'         => $request->email,
            'address'       => $request->address,
            'description'   => $request->description,
            'status'        => $request->status,
            'vendor_id'     => $currentUserId,
        ] );

        $customerDetails = [
            'id'    => $customer->id,
            'name'  => $customer->customer_name,
            'phone' => $customer->phone,
            'email' => $customer->email,
        ];

        return response()->json( [
            'status'          => 200,
            'message'         => 'Customer Added Successfully!',
            'customerDetails' => $customerDetails,
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
            'message' => Customer::find( $id ),
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
            'customer_name' => 'required',
            'phone'         => 'required|unique:customers,phone,' . $id . ',id,vendor_id,' . vendorId(),
            'email'         => 'nullable|unique:customers,email,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'errors'  => $validator->messages(),
                'message' => 'Please check the required fields.',
            ] );
        } else {
            Customer::find( $id )->update( [
                'customer_name' => $request->customer_name,
                'customer_slug' => Str::slug( $request->customer_name ),
                'customer_type' => $request->customer_type,
                'phone'         => $request->phone,
                'email'         => $request->email,
                'address'       => $request->address,
                'description'   => $request->description,
                'status'        => $request->status,
            ] );

            return response()->json( [
                'status'  => 200,
                'message' => 'Customer Updated Successfully!',
            ] );
        }
    }

    /**
     * Remove the resource from storage.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        Customer::find( $id )->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Customer Deleted Successfully !',
        ] );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        $data         = Customer::find( $id );
        $data->status = $data->status == 'active' ? 'deactive' : 'active';
        $data->save();

        if ( $data->status == 'active' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Customer Active Successfully !',
            ] );
        } else {
            return response()->json( [
                'status'  => 200,
                'message' => 'Customer Deactive Successfully !',
            ] );
        }

    }
}
