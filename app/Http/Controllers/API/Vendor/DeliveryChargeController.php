<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCharge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class DeliveryChargeController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return response()->json( [
            'status'         => 200,
            'deliveryCharge' => DeliveryCharge::latest()->where( 'vendor_id', vendorId() )->select( 'id', 'area', 'charge', 'status' )->get(),
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
            'area' => 'required|unique:delivery_charges,area,NULL,id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        DeliveryCharge::create( [
            'user_id'   => Auth::id(),
            'vendor_id' => vendorId(),
            'area'      => $request->area,
            'charge'    => $request->charge,
            'status'    => $request->status,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => 'Delivery charge Added Successfully!',
        ] );
    }

    /**
     * Display the resource.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function edit( $id ) {

        $deliveryCharge = DeliveryCharge::select( 'id', 'area', 'charge', 'status' )->find( $id );

        if ( !$deliveryCharge ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Not found!',
            ] );
        }
        return response()->json( [
            'status'  => 200,
            'message' => $deliveryCharge,
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
            'area' => 'required|unique:delivery_charges,area,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        } else {
            $deliveryCharge = DeliveryCharge::find( $id );

            if ( !$deliveryCharge ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Not found!',
                ] );
            }

            $deliveryCharge->area    = $request->area;
            $deliveryCharge->charge  = $request->charge;
            $deliveryCharge->user_id = Auth::id();
            $deliveryCharge->save();

            return response()->json( [
                'status'  => 200,
                'message' => 'Delivery charge Updated Successfully!',
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
        DeliveryCharge::find( $id )->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Delivery charge Successfully !',
        ] );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        $data         = DeliveryCharge::find( $id );
        $data->status = $data->status == 'active' ? 'deactive' : 'active';
        $data->save();

        if ( $data->status == 'active' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Delivery charge Active Successfully !',
            ] );
        } else {
            return response()->json( [
                'status'  => 200,
                'message' => 'Delivery charge Deactive Successfully !',
            ] );
        }

    }
}
