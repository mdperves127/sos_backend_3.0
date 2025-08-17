<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\CourierCredential;
use App\Services\PathaoService;
use App\Service\Affi\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller {
    //
    function index() {
        return DashboardService::index();
    }

    function orderVsRevenue() {
        return DashboardService::orderVsRevenue();

    }

    public function getCities() {

        $credential = CourierCredential::where( 'vendor_id', 2 )->first();

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

        // $credential = CourierCredential::where( 'vendor_id', $vendor_id )->first();
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

        $credential = CourierCredential::where( 'vendor_id', 2 )->first();

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
}
