<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\CourierCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CourierCredentialController extends Controller {

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $data = CourierCredential::where( 'vendor_id', vendorId() )->get();
        return $this->response( $data );
    }

    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store( Request $request ) {

        $validator = Validator::make( $request->all(), [
            // 'courier_name'    => 'required|unique:courier_credentials,courier_name,NULL,id,vendor_id,' . vendorId(),
            'courier_name'    => 'required|in:pathao,steadfast,redx',
            'api_key'         => 'required|unique:courier_credentials,api_key,NULL,id,vendor_id,' . vendorId(),
            'secret_key'      => 'required|unique:courier_credentials,secret_key,NULL,id,vendor_id,' . vendorId(),
            'client_email'    => 'nullable|email',
            'client_password' => 'nullable',
            'store_id'        => 'nullable',
            'status'          => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 422,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $check = CourierCredential::where( 'vendor_id', vendorId() )->where( 'courier_name', $request->courier_name )->first();
        if ( $check ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Courier already exists',
            ] );
        }

        $isFirstCourier = CourierCredential::where( 'vendor_id', vendorId() )->doesntExist();

        $data                  = new CourierCredential();
        $data->vendor_id       = vendorId(); //auth()->user()->id;
        $data->courier_name    = $request->courier_name;
        $data->api_key         = $request->api_key;
        $data->secret_key      = $request->secret_key;
        $data->client_email    = $request->client_email ?? null;
        $data->client_password = $request->client_password ?? null;
        $data->store_id        = $request->store_id ?? null;
        $data->status          = $isFirstCourier ? 'active' : $request->status;
        $data->default         = $isFirstCourier ? 'yes' : 'no';
        $data->save();
        return $this->response( 'Created successfull' );
    }

    /**
     * Display the resource.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function edit( $id ) {

        return $this->response( CourierCredential::find( $id ) );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update( Request $request, $id ) {

        $validator = Validator::make( $request->all(), [
            // 'courier_name'    => 'required|unique:courier_credentials,courier_name,' . $id . ',id,vendor_id,' . vendorId(),
            'courier_name'    => 'required|in:pathao,steadfast,redx',
            'api_key'         => 'required|unique:courier_credentials,api_key,' . $id . ',id,vendor_id,' . vendorId(),
            'secret_key'      => 'required|unique:courier_credentials,secret_key,' . $id . ',id,vendor_id,' . vendorId(),
            'client_email'    => 'required_if:courier_name,pathao|email',
            'client_password' => 'required_if:courier_name,pathao',
            'store_id'        => 'nullable',
            'status'          => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        }

        $check = CourierCredential::where( 'vendor_id', vendorId() )->where( 'courier_name', $request->courier_name )->whereNot( 'id', $id )->first();
        if ( $check ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Courier already exists',

            ] );
        }

        $isFirstCourier = CourierCredential::where( 'vendor_id', vendorId() )->doesntExist();

        $data               = CourierCredential::find( $id );
        $data->courier_name = $request->courier_name;
        $data->api_key      = $request->api_key;
        $data->secret_key   = $request->secret_key;

        if ( $request->courier_name === 'pathao' ) {
            $data->client_email    = $request->client_email;
            $data->client_password = $request->client_password;
            $data->store_id        = $request->store_id;
        } else {
            $data->client_email    = null;
            $data->client_password = null;
            $data->store_id        = null;
        }

        $data->status = $isFirstCourier ? 'active' : $request->status;
        $data->save();
        return $this->response( 'Update successfull' );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        CourierCredential::find( $id )->delete();
        return $this->response( 'Deleted successfull' );
    }

    /**
     * Change the resource status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */
    public function status( $id ) {
        // Find the courier credential based on the provided ID and vendor ID
        $data = CourierCredential::where( 'id', $id )->where( 'vendor_id', vendorId() )->first();

        // Check if the courier exists
        if ( !$data ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Courier not found!',
            ] );
        }

        // Toggle status
        if ( $data->status == 'active' ) {
            // If it's currently active, we need to check if we can deactivate it
            // Ensure at least one courier remains active
            $activeCount = CourierCredential::where( 'vendor_id', vendorId() )->where( 'status', 'active' )->count();

            if ( $activeCount > 1 ) {
                // If more than one is active, allow deactivation
                $data->status = 'deactive';
                $data->save();
                return response()->json( [
                    'status'  => 200,
                    'message' => 'Courier Deactivated Successfully!',
                ] );
            } else {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'At least one courier must remain active!',
                ] );
            }
        } else {
            // If it's currently inactive, activate it
            $data->status = 'active';
            $data->save();
            return response()->json( [
                'status'  => 200,
                'message' => 'Courier Activated Successfully!',
            ] );
        }
    }

    /**
     * Change the default status.
     *
     * @param  \{{ namespacedParentModel }}  ${{ parentModelVariable }}
     * @return \Illuminate\Http\Response
     */

    public function default( $id ) {
        // Find the courier credential based on the provided ID and vendor ID
        $data = CourierCredential::where( 'id', $id )->where( 'vendor_id', vendorId() )->first();

        // Check if the courier exists
        if ( !$data ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Courier not found!',
            ] );
        }

        // Set all other couriers for this vendor to not be default
        CourierCredential::where( 'vendor_id', vendorId() )->update( ['default' => 'no'] );

        // Set the selected courier as default
        $data->default = 'yes';
        $data->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Default courier set successfully!',
        ] );
    }

}
