<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAndPickupAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class DeliveryAndPickupAddressController extends Controller {
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {

        $deliveryAndPickupAddress = DeliveryAndPickupAddress::latest()
            ->when( request( 'type' ) == "pickup", function ( $query ) {
                return $query->where( 'type', 'pickup' );
            } )
            ->when( request( 'type' ) == "delivery", function ( $query ) {
                return $query->where( 'type', 'delivery' );
            } )
            ->where( 'vendor_id', vendorId() )->select( 'id', 'address', 'type', 'status' )->get();

        return response()->json( [
            'status'                   => 200,
            'deliveryAndPickupAddress' => $deliveryAndPickupAddress,
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
            'address' => 'required|unique:delivery_and_pickup_addresses,address,NULL,id,vendor_id,' . vendorId() . ',type,' . $request->type,
            'type'    => 'required',
            'status'  => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $address = DeliveryAndPickupAddress::create( [
            'user_id'   => Auth::id(),
            'vendor_id' => vendorId(),
            'address'   => $request->address,
            'type'      => $request->type,
            'status'    => $request->status,
        ] );

        return response()->json( [
            'status'  => 200,
            'message' => ( $request->type == "delivery" ? 'Delivery' : 'Pickup' ) . ' address Added Successfully!',
            'id'      => $address->id,
        ] );
    }

    /**
     * Display the resource.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function edit( $id ) {

        $deliveryAndPickupAddress = DeliveryAndPickupAddress::select( 'id', 'address', 'type', 'status' )->find( $id );

        if ( !$deliveryAndPickupAddress ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Not found!',
            ] );
        }
        return response()->json( [
            'status'                   => 200,
            'deliveryAndPickupAddress' => $deliveryAndPickupAddress,
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
            'address' => 'required|unique:delivery_and_pickup_addresses,address,' . $id . ',id,vendor_id,' . vendorId() . ',type,' . $request->type,
            'type'    => 'required',
            'status'  => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        } else {
            $data = DeliveryAndPickupAddress::find( $id );

            if ( !$data ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Not found!',
                ] );
            }

            $data->address = $request->address;
            $data->type    = $request->type;
            $data->status  = $request->status;
            $data->user_id = Auth::id();
            $data->save();

            return response()->json( [
                'status'  => 200,
                'message' => ( $request->type == "delivery" ? 'Delivery' : 'Pickup' ) . ' address update Successfully!',
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
        DeliveryAndPickupAddress::find( $id )->delete();
        return response()->json( [
            'status'  => 200,
            'message' => 'Address delete Successfully !',
        ] );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        $data         = DeliveryAndPickupAddress::find( $id );
        $data->status = $data->status == 'active' ? 'deactive' : 'active';
        $data->save();

        if ( $data->status == 'active' ) {
            return response()->json( [
                'status'  => 200,
                'message' => ( $data->type == "delivery" ? 'Delivery' : 'Pickup' ) . ' address active Successfully!',
            ] );
        } else {
            return response()->json( [
                'status'  => 200,
                'message' => ( $data->type == "delivery" ? 'Delivery' : 'Pickup' ) . ' address deactive Successfully!',
            ] );
        }

    }
}
