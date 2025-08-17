<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\CourierCredential;
use App\Models\Note;
use App\Services\PathaoService;
use App\Services\RedxService;
use App\Service\Vendor\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller {
    //
    function index() {
        return DashboardService::index();
    }

    function orderVsRevenue() {
        return DashboardService::orderVsRevenue();
    }

    function topten() {
        return DashboardService::topten();

    }

    public function myNote() {
        $myNotes = Note::where( 'user_id', Auth::id() )->orWhereNull( 'user_id' )->paginate( 10 );
        return response()->json( [
            'status' => 200,
            'notes'  => $myNotes,
        ] );
    }

    public function getCity( $vendor_id ) {
        $credential = courierCredential( $vendor_id, 'pathao' );

        if ( !$credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Courier not found!',
            ] );
        }

        $access_token = PathaoService::getToken( $credential->api_key, $credential->secret_key, $credential->client_email, $credential->client_password );

        if ( $access_token ) {
            return PathaoService::cities( $access_token );
        }
    }

    public function getZones( $city_id, $vendor_id ) {

        $credential = courierCredential( $vendor_id, 'pathao' );

        if ( !$credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Courier not found!',
            ] );
        }

        $access_token = PathaoService::getToken( $credential->api_key, $credential->secret_key, $credential->client_email, $credential->client_password );

        if ( $access_token ) {
            return PathaoService::getZone( $access_token, $city_id );
        }
    }

    public function getArea( $zone_id, $vendor_id ) {

        $credential = courierCredential( $vendor_id, 'pathao' );

        if ( !$credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Courier not found!',
            ] );
        }

        $access_token = PathaoService::getToken( $credential->api_key, $credential->secret_key, $credential->client_email, $credential->client_password );

        if ( $access_token ) {
            return PathaoService::getArea( $access_token, $zone_id );
        }
    }

    public function newShipmentOrder( Request $request ) {

        $credential = CourierCredential::where( 'vendor_id', $request->vendor_id )->first();

        if ( !$credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Courier not found!',
            ] );
        }

        $access_token = PathaoService::getToken( $credential->api_key, $credential->secret_key, $credential->client_email, $credential->client_password );
        $newOrder     = $request->all();
        if ( $access_token ) {
            $order = PathaoService::newOrder( $access_token, $credential->store_id, $newOrder );

            return response()->json( [
                'status'         => 200,
                'consignment_id' => $order['data']['consignment_id'],
                'delivery_fee'   => $order['data']['delivery_fee'],
            ] );
        }
        return 'failed';

    }

    //--------------------------Redx----------------------

    public function getRedxArea() {
        if ( env( 'REDX_MODE' ) == "sandbox" ) {
            $token = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI2ODQ1NSIsImlhdCI6MTczNzQzODg1MywiaXNzIjoiWjdYdVJFUzlXc1cxR04xWDVSWmRmSXo4b2VyaW1UMmoiLCJzaG9wX2lkIjo2ODQ1NSwidXNlcl9pZCI6MTYxNjE5fQ.bNS7eUDQcc-OW_Ox8WAkD7d_8SzT6Jyp0X9s101EwKw";
        } else {
            $token = courierCredential( vendorId(), 'redx' );
        }

        if ( is_object( $token ) ) {
            $apiKey = $token->api_key;
        } elseif ( is_array( $token ) ) {
            $apiKey = $token['api_key'];
        } else {
            $apiKey = $token;
        }

        $areas = RedxService::getArea( $apiKey );
        $areas = json_decode( $areas, true );
        return $areas;
    }
}
