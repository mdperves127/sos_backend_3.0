<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Services\PathaoService;
use App\Services\RedxService;
use App\Service\Vendor\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller {
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

    public function getCity( $tenant_id = null ) {
        return $this->pathaoListResponse(
            $this->resolveTenantId( $tenant_id ),
            fn ( $token ) => PathaoService::cities( $token )
        );
    }

    public function getZones( $city_id, $tenant_id = null ) {
        return $this->pathaoListResponse(
            $this->resolveTenantId( $tenant_id ),
            fn ( $token ) => PathaoService::getZone( $token, $city_id )
        );
    }

    public function getArea( $zone_id, $tenant_id = null ) {
        return $this->pathaoListResponse(
            $this->resolveTenantId( $tenant_id ),
            fn ( $token ) => PathaoService::getArea( $token, $zone_id )
        );
    }

    public function newShipmentOrder( Request $request, $tenant_id = null ) {
        $tenantId   = $this->resolveTenantId( $tenant_id ?? $request->input( 'tenant_id' ) );
        $credential = courierCredentialByTenant( $tenantId, 'pathao' );

        if ( ! $credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Pathao courier not found!',
            ], 404 );
        }

        $accessToken = PathaoService::getToken(
            $credential->api_key,
            $credential->secret_key,
            $credential->client_email,
            $credential->client_password
        );

        if ( ! is_string( $accessToken ) || $accessToken === '' ) {
            return response()->json( [
                'status'  => 400,
                'message' => is_array( $accessToken ) ? ( $accessToken['message'] ?? 'Pathao token failed.' ) : 'Pathao token failed.',
                'details' => is_array( $accessToken ) ? $accessToken : null,
            ], 400 );
        }

        $order = PathaoService::newOrder( $accessToken, $credential->store_id, $request->all() );

        if ( ! PathaoService::isCreateSuccess( $order ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => $order['message'] ?? 'Pathao order creation failed.',
                'details' => $order['details'] ?? $order,
            ], 400 );
        }

        return response()->json( [
            'status'         => 200,
            'message'        => 'Pathao order created.',
            'consignment_id' => $order['data']['consignment_id'],
            'delivery_fee'   => $order['data']['delivery_fee'] ?? null,
            'data'           => $order['data'],
        ] );
    }

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

    private function resolveTenantId( $tenantId = null )
    {
        return $tenantId
            ?: request()->input( 'tenant_id' )
            ?: ( function_exists( 'tenant' ) && tenant() ? tenant( 'id' ) : null );
    }

    private function pathaoListResponse( $tenantId, callable $fetcher )
    {
        $credential = courierCredentialByTenant( $tenantId, 'pathao' );

        if ( ! $credential ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Pathao courier not found!',
            ], 404 );
        }

        $accessToken = PathaoService::getToken(
            $credential->api_key,
            $credential->secret_key,
            $credential->client_email,
            $credential->client_password
        );

        if ( ! is_string( $accessToken ) || $accessToken === '' ) {
            return response()->json( [
                'status'  => 400,
                'message' => is_array( $accessToken ) ? ( $accessToken['message'] ?? 'Pathao token failed.' ) : 'Pathao token failed.',
                'details' => is_array( $accessToken ) ? $accessToken : null,
            ], 400 );
        }

        $data = $fetcher( $accessToken );

        if ( PathaoService::isError( $data ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => $data['message'] ?? 'Pathao request failed.',
                'details' => $data['details'] ?? $data,
            ], 400 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $data,
        ] );
    }
}
