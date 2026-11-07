<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewaySetting;
use App\Services\EpsConfig;
use App\Services\TenantPaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PaymentGatewaySettingController extends Controller
{
    /**
     * GET /api/tenant/payment-gateways
     */
    public function index()
    {
        return response()->json( [
            'status'  => 200,
            'message' => 'OK',
            'data'    => TenantPaymentGatewayService::publicStatusAll(),
        ] );
    }

    /**
     * GET /api/tenant/payment-gateways/{gateway}
     */
    public function show( string $gateway )
    {
        if ( ! in_array( $gateway, PaymentGatewaySetting::GATEWAYS, true ) ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Unknown payment gateway.',
                'data'    => null,
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'OK',
            'data'    => TenantPaymentGatewayService::publicStatus( $gateway ),
        ] );
    }

    /**
     * PUT/POST /api/tenant/payment-gateways/{gateway}
     */
    public function upsert( Request $request, string $gateway )
    {
        if ( ! in_array( $gateway, PaymentGatewaySetting::GATEWAYS, true ) ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Unknown payment gateway.',
                'data'    => null,
            ], 404 );
        }

        $rules = [
            'enabled' => ['sometimes', 'boolean'],
        ];

        if ( $gateway === PaymentGatewaySetting::GATEWAY_UDDOKTAPAY ) {
            $rules += [
                'api_key'  => ['nullable', 'string', 'max:500'],
                'base_url' => ['nullable', 'string', 'max:255', 'url'],
            ];
        }

        if ( $gateway === PaymentGatewaySetting::GATEWAY_EPS ) {
            $rules += [
                'mode'                => ['nullable', 'string', Rule::in( [EpsConfig::MODE_SANDBOX, EpsConfig::MODE_LIVE] )],
                'username'            => ['nullable', 'string', 'max:255'],
                'password'            => ['nullable', 'string', 'max:500'],
                'hash_key'            => ['nullable', 'string', 'max:500'],
                'merchant_id'         => ['nullable', 'string', 'max:255'],
                'store_id'            => ['nullable', 'string', 'max:255'],
                'transaction_type_id' => ['nullable', 'integer', 'min:1', 'max:10'],
            ];
        }

        $validator = Validator::make( $request->all(), $rules );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'success' => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()->toArray(),
                'data'    => null,
            ], 400 );
        }

        $data    = $validator->validated();
        $enabled = array_key_exists( 'enabled', $data )
            ? (bool) $data['enabled']
            : (bool) ( TenantPaymentGatewayService::find( $gateway )?->enabled ?? false );

        unset( $data['enabled'] );

        $existing = TenantPaymentGatewayService::find( $gateway );
        $mergedPreview = TenantPaymentGatewayService::mergeCredentials(
            $gateway,
            is_array( $existing?->credentials ) ? $existing->credentials : [],
            $data
        );

        if ( $enabled && ! TenantPaymentGatewayService::credentialsAreComplete( $gateway, $mergedPreview ) ) {
            return response()->json( [
                'status'  => 400,
                'success' => false,
                'message' => 'Cannot enable gateway until all required credentials are set.',
                'errors'  => [
                    'credentials' => ['Required fields: ' . implode( ', ', TenantPaymentGatewayService::requiredKeysFor( $gateway ) )],
                ],
                'data'    => null,
            ], 400 );
        }

        $setting = TenantPaymentGatewayService::upsert( $gateway, $enabled, $data );

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => ucfirst( $gateway ) . ' payment gateway settings saved.',
            'data'    => TenantPaymentGatewayService::publicStatus( $gateway, $setting ),
        ] );
    }

    /**
     * DELETE /api/tenant/payment-gateways/{gateway}
     * Clears credentials and disables the gateway.
     */
    public function destroy( string $gateway )
    {
        if ( ! in_array( $gateway, PaymentGatewaySetting::GATEWAYS, true ) ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Unknown payment gateway.',
                'data'    => null,
            ], 404 );
        }

        TenantPaymentGatewayService::disableAndClear( $gateway );

        return response()->json( [
            'status'  => 200,
            'success' => true,
            'message' => ucfirst( $gateway ) . ' payment gateway credentials cleared.',
            'data'    => TenantPaymentGatewayService::publicStatus( $gateway ),
        ] );
    }
}
