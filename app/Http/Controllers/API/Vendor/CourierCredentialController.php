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
        return $this->responseData( $data );
    }

    /**
     * Store the newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store( Request $request ) {

        $validator = Validator::make( $request->all(), [
            'courier_name'    => 'required|in:pathao,steadfast,redx',
            'api_key'         => 'required',
            'secret_key'      => 'nullable|required_unless:courier_name,redx',
            'client_email'    => 'nullable|email|required_if:courier_name,pathao',
            'client_password' => 'nullable|required_if:courier_name,pathao',
            'store_id'        => 'nullable|required_if:courier_name,pathao',
            'status'          => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        // Pathao: allow multiple credentials (different store_id). Others: one per courier type.
        if ( $request->courier_name === 'pathao' ) {
            $duplicateStore = CourierCredential::where( 'vendor_id', vendorId() )
                ->where( 'courier_name', 'pathao' )
                ->where( 'store_id', $request->store_id )
                ->exists();

            if ( $duplicateStore ) {
                return response()->json( [
                    'status'  => 422,
                    'message' => 'This Pathao store_id already exists.',
                ] );
            }
        } else {
            $check = CourierCredential::where( 'vendor_id', vendorId() )
                ->where( 'courier_name', $request->courier_name )
                ->first();

            if ( $check ) {
                return response()->json( [
                    'status'  => 422,
                    'message' => 'Courier already exists',
                ] );
            }
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
        return $this->responseMessage( 'Created Successfully' );
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
            'courier_name'    => 'required|in:pathao,steadfast,redx',
            'api_key'         => 'required',
            'secret_key'      => 'nullable|required_unless:courier_name,redx',
            'client_email'    => 'nullable|email|required_if:courier_name,pathao',
            'client_password' => 'nullable|required_if:courier_name,pathao',
            'store_id'        => 'nullable|required_if:courier_name,pathao',
            'status'          => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        }

        $data = CourierCredential::where( 'id', $id )->where( 'vendor_id', vendorId() )->first();
        if ( ! $data ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Courier not found!',
            ], 404 );
        }

        if ( $request->courier_name === 'pathao' ) {
            $duplicateStore = CourierCredential::where( 'vendor_id', vendorId() )
                ->where( 'courier_name', 'pathao' )
                ->where( 'store_id', $request->store_id )
                ->where( 'id', '!=', $id )
                ->exists();

            if ( $duplicateStore ) {
                return response()->json( [
                    'status'  => 422,
                    'message' => 'This Pathao store_id already exists.',
                ] );
            }
        } else {
            $check = CourierCredential::where( 'vendor_id', vendorId() )
                ->where( 'courier_name', $request->courier_name )
                ->where( 'id', '!=', $id )
                ->first();

            if ( $check ) {
                return response()->json( [
                    'status'  => 422,
                    'message' => 'Courier already exists',
                ] );
            }
        }

        $isFirstCourier = CourierCredential::where( 'vendor_id', vendorId() )->doesntExist();

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
        return $this->responseMessage( 'Update Successfully' );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy( $id ) {
        CourierCredential::find( $id )->delete();
        return $this->responseMessage( 'Deleted Successfully' );
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

        if ( $data->status == 'active' ) {
            $data->status = 'deactive';
            if ( $data->default === 'yes' ) {
                $data->default = 'no';
            }
            $data->save();

            return response()->json( [
                'status'  => 200,
                'message' => 'Courier Deactivated Successfully!',
            ] );
        }

        $data->status = 'active';
        $data->save();

        return response()->json( [
            'status'  => 200,
            'message' => 'Courier Activated Successfully!',
        ] );
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

    /**
     * Steadfast: current balance for a credential.
     */
    public function steadfastBalance( $id )
    {
        $credential = $this->steadfastCredential( $id );
        if ( ! $credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Steadfast credential not found.',
            ], 404 );
        }

        $result = \App\Services\SteadFastService::getBalance( $credential->api_key, $credential->secret_key );

        if ( (int) ( $result['status'] ?? 0 ) !== 200 ) {
            return response()->json( [
                'status'  => 400,
                'message' => $result['message'] ?? 'Unable to fetch Steadfast balance.',
                'data'    => $result,
            ], 400 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Steadfast balance fetched.',
            'data'    => [
                'current_balance' => $result['current_balance'] ?? null,
            ],
        ] );
    }

    /**
     * Steadfast: delivery status by consignment_id / invoice / tracking_code.
     */
    public function steadfastStatus( Request $request, $id )
    {
        $credential = $this->steadfastCredential( $id );
        if ( ! $credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Steadfast credential not found.',
            ], 404 );
        }

        $result = \App\Services\SteadFastService::getDeliveryStatus(
            $credential->api_key,
            $credential->secret_key,
            $request->query( 'consignment_id' ),
            $request->query( 'invoice' ),
            $request->query( 'tracking_code' )
        );

        if ( (int) ( $result['status'] ?? 0 ) !== 200 ) {
            return response()->json( [
                'status'  => 400,
                'message' => $result['message'] ?? 'Unable to fetch Steadfast delivery status.',
                'data'    => $result,
            ], 400 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Steadfast delivery status fetched.',
            'data'    => [
                'delivery_status' => $result['delivery_status'] ?? null,
            ],
        ] );
    }

    /**
     * Steadfast: create a return request.
     */
    public function steadfastReturnRequest( Request $request, $id )
    {
        $credential = $this->steadfastCredential( $id );
        if ( ! $credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Steadfast credential not found.',
            ], 404 );
        }

        $result = \App\Services\SteadFastService::createReturnRequest(
            $credential->api_key,
            $credential->secret_key,
            $request->only( ['consignment_id', 'invoice', 'tracking_code', 'reason'] )
        );

        if ( isset( $result['message'] ) && (int) ( $result['status'] ?? 0 ) >= 400 ) {
            return response()->json( [
                'status'  => 400,
                'message' => $result['message'],
                'data'    => $result,
            ], 400 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Steadfast return request submitted.',
            'data'    => $result,
        ] );
    }

    /**
     * Pathao: city list for a credential (or default Pathao).
     */
    public function pathaoCities( $id = null )
    {
        return $this->withPathaoToken( $id, function ( $token ) {
            $data = \App\Services\PathaoService::cities( $token );
            if ( \App\Services\PathaoService::isError( $data ) ) {
                return $this->pathaoError( $data );
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Pathao cities fetched.',
                'data'    => $data,
            ] );
        } );
    }

    /**
     * Pathao: zones by city.
     */
    public function pathaoZones( $cityId, $id = null )
    {
        return $this->withPathaoToken( $id, function ( $token ) use ( $cityId ) {
            $data = \App\Services\PathaoService::getZone( $token, $cityId );
            if ( \App\Services\PathaoService::isError( $data ) ) {
                return $this->pathaoError( $data );
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Pathao zones fetched.',
                'data'    => $data,
            ] );
        } );
    }

    /**
     * Pathao: areas by zone.
     */
    public function pathaoAreas( $zoneId, $id = null )
    {
        return $this->withPathaoToken( $id, function ( $token ) use ( $zoneId ) {
            $data = \App\Services\PathaoService::getArea( $token, $zoneId );
            if ( \App\Services\PathaoService::isError( $data ) ) {
                return $this->pathaoError( $data );
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Pathao areas fetched.',
                'data'    => $data,
            ] );
        } );
    }

    /**
     * Pathao: store list.
     */
    public function pathaoStores( Request $request, $id = null )
    {
        return $this->withPathaoToken( $id, function ( $token ) use ( $request ) {
            $data = \App\Services\PathaoService::stores( $token, (int) $request->query( 'page', 1 ) );
            if ( \App\Services\PathaoService::isError( $data ) ) {
                return $this->pathaoError( $data );
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Pathao stores fetched.',
                'data'    => $data,
            ] );
        } );
    }

    /**
     * Pathao: create pickup store.
     */
    public function pathaoCreateStore( Request $request, $id = null )
    {
        return $this->withPathaoToken( $id, function ( $token, $credential ) use ( $request ) {
            $result = \App\Services\PathaoService::createStore( $token, $request->all() );
            if ( \App\Services\PathaoService::isError( $result ) || ( isset( $result['status'] ) && (int) $result['status'] >= 400 && ! isset( $result['data'] ) ) ) {
                return $this->pathaoError( $result );
            }

            // Optionally persist new store_id on this credential.
            $storeId = $result['data']['store_id'] ?? $result['store_id'] ?? null;
            if ( $storeId && $request->boolean( 'set_as_store' ) ) {
                $credential->store_id = $storeId;
                $credential->save();
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Pathao store created.',
                'data'    => $result['data'] ?? $result,
            ] );
        } );
    }

    /**
     * Pathao: order/consignment details.
     */
    public function pathaoOrderDetails( $consignmentId, $id = null )
    {
        return $this->withPathaoToken( $id, function ( $token ) use ( $consignmentId ) {
            $data = \App\Services\PathaoService::orderDetails( $token, $consignmentId );
            if ( \App\Services\PathaoService::isError( $data ) ) {
                return $this->pathaoError( $data );
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Pathao order details fetched.',
                'data'    => $data['data'] ?? $data,
            ] );
        } );
    }

    /**
     * Pathao: price calculation.
     */
    public function pathaoPrice( Request $request, $id = null )
    {
        return $this->withPathaoToken( $id, function ( $token, $credential ) use ( $request ) {
            $payload = $request->all();
            if ( empty( $payload['store_id'] ) ) {
                $payload['store_id'] = $credential->store_id;
            }

            $data = \App\Services\PathaoService::priceCalculation( $token, $payload );
            if ( \App\Services\PathaoService::isError( $data ) || ( isset( $data['status'] ) && (int) $data['status'] === 422 && ! isset( $data['data'] ) ) ) {
                return $this->pathaoError( $data );
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Pathao price calculated.',
                'data'    => $data['data'] ?? $data,
            ] );
        } );
    }

    private function withPathaoToken( $id, callable $callback )
    {
        $credential = $this->pathaoCredential( $id );
        if ( ! $credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Pathao credential not found.',
            ], 404 );
        }

        $token = \App\Services\PathaoService::getToken(
            $credential->api_key,
            $credential->secret_key,
            $credential->client_email,
            $credential->client_password
        );

        if ( ! is_string( $token ) || $token === '' ) {
            return response()->json( [
                'status'  => 400,
                'message' => is_array( $token ) ? ( $token['message'] ?? 'Pathao token failed.' ) : 'Pathao token failed.',
                'data'    => is_array( $token ) ? $token : null,
            ], 400 );
        }

        return $callback( $token, $credential );
    }

    private function pathaoError( array $payload )
    {
        return response()->json( [
            'status'  => 400,
            'message' => $payload['message'] ?? 'Pathao request failed.',
            'data'    => $payload,
        ], 400 );
    }

    private function pathaoCredential( $id = null ): ?CourierCredential
    {
        if ( $id ) {
            return CourierCredential::where( 'id', $id )
                ->where( 'vendor_id', vendorId() )
                ->where( 'courier_name', 'pathao' )
                ->first();
        }

        return courierCredential( vendorId(), 'pathao' );
    }

    private function steadfastCredential( $id ): ?CourierCredential
    {
        return CourierCredential::where( 'id', $id )
            ->where( 'vendor_id', vendorId() )
            ->where( 'courier_name', 'steadfast' )
            ->first();
    }

}
