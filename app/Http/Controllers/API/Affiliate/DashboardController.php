<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\Controller;
use App\Services\PathaoService;
use App\Service\Affi\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller {
    function index() {
        return DashboardService::index();
    }

    function orderVsRevenue() {
        return DashboardService::orderVsRevenue();
    }

    public function getCities( Request $request, $tenant_id = null ) {
        return $this->pathaoListResponse(
            $this->resolveTenantId( $request, $tenant_id ),
            fn ( $token ) => PathaoService::cities( $token )
        );
    }

    public function getZones( Request $request, $city_id, $tenant_id = null ) {
        return $this->pathaoListResponse(
            $this->resolveTenantId( $request, $tenant_id ),
            fn ( $token ) => PathaoService::getZone( $token, $city_id )
        );
    }

    public function getArea( Request $request, $zone_id, $tenant_id = null ) {
        return $this->pathaoListResponse(
            $this->resolveTenantId( $request, $tenant_id ),
            fn ( $token ) => PathaoService::getArea( $token, $zone_id )
        );
    }

    public function newShipmentOrder( Request $request, $tenant_id = null ) {
        $tenantId   = $this->resolveTenantId( $request, $tenant_id );
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

    private function resolveTenantId( Request $request, $tenantId = null )
    {
        return $tenantId
            ?: $request->input( 'tenant_id' )
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
