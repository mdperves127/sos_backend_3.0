<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\WoocommerceCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WoocommerceCredentialController extends Controller {

    /**
     * Display a listing of the resource.
     */
    public function index() {

        $data = WoocommerceCredential::where( 'vendor_id', vendorId() )->get();
        return $this->response( $data );
    }

    /**
     * Store the newly created resource in storage.
     */
    public function store( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'wc_key'    => 'required|unique:woocommerce_credentials',
            'wc_secret' => 'required|unique:woocommerce_credentials',
            'wc_url'    => 'required|unique:woocommerce_credentials',
        ], [
            'wc_key.unique'    => 'This appkey register with another vendor',
            'wc_secret.unique' => 'This secretkey Already register with another vendor',
            'wc_url.unique'    => 'This website Already register with another vendor',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $data            = new WoocommerceCredential();
        $data->vendor_id = auth()->user()->id;
        $data->wc_key    = $request->wc_key;
        $data->wc_secret = $request->wc_secret;
        $data->wc_url    = $request->wc_url;
        $data->save();
        return $this->response( "Created successfull" );
    }

    /**
     * Edit the resource.
     */
    public function edit( $id ) {

        return $this->response( WoocommerceCredential::find( $id ) );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update( Request $request, $id ) {

        $validator = Validator::make( $request->all(), [
            'wc_key'    => 'required|unique:woocommerce_credentials,wc_key,' . $id . ',id,vendor_id,' . vendorId(),
            'wc_secret' => 'required|unique:woocommerce_credentials,wc_secret,' . $id . ',id,vendor_id,' . vendorId(),
            'wc_url'    => 'required|unique:woocommerce_credentials,wc_url,' . $id . ',id,vendor_id,' . vendorId(),
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $data            = WoocommerceCredential::find( $id );
        $data->vendor_id = auth()->user()->id;
        $data->wc_key    = $request->wc_key;
        $data->wc_secret = $request->wc_secret;
        $data->wc_url    = $request->wc_url;
        $data->save();
        return $this->response( "Update successfull" );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy( $id ) {
        WoocommerceCredential::find( $id )->delete();
        return $this->response( 'Deleted successfull' );
    }
}
