<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateAddonRequest;
use App\Models\Addon;
use App\Models\TenantInstalledAddon;
use App\Services\AddonActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantAddonController extends Controller
{
    /**
     * List addons for the current tenant, filtered by tenant type (dropshipper | merchant).
     */
    public function index( Request $request ): JsonResponse
    {
        $tenantType = $this->tenantTypeOrFail();

        $activeIds = TenantInstalledAddon::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->where( 'status', 'active' )
            ->pluck( 'addon_id' )
            ->all();

        $query = Addon::on( 'mysql' )
            ->where( 'for_tenant', $tenantType )
            ->latest( 'id' );

        if ( $request->filled( 'addon_type' ) ) {
            $query->where( 'addon_type', $request->input( 'addon_type' ) );
        }

        $addons = $query->get()->map( function ( Addon $addon ) use ( $activeIds ) {
            $row = $addon->toArray();
            $row['is_active'] = in_array( $addon->id, $activeIds, true );
            $row['is_free']   = (float) $addon->price <= 0;

            return $row;
        } );

        return response()->json( [
            'status'      => 200,
            'message'     => 'Addons fetched successfully.',
            'tenant_type' => $tenantType,
            'data'        => [
                'system_addons'     => $addons->where( 'addon_type', 'system' )->values(),
                'membership_addons' => $addons->where( 'addon_type', 'membership' )->values(),
                'all'               => $addons->values(),
            ],
        ] );
    }

    /**
     * List installed / active addons for the current tenant.
     */
    public function installed(): JsonResponse
    {
        $this->tenantTypeOrFail();

        $items = TenantInstalledAddon::on( 'mysql' )
            ->with( 'addon' )
            ->where( 'tenant_id', tenant()->id )
            ->where( 'status', 'active' )
            ->latest( 'activated_at' )
            ->get();

        return response()->json( [
            'status'  => 200,
            'message' => 'Installed addons fetched successfully.',
            'data'    => $items,
        ] );
    }

    /**
     * Install / activate an addon (free instant, wallet, or payment gateway redirect).
     */
    public function activate( ActivateAddonRequest $request, Addon $addon )
    {
        $tenantType = $this->tenantTypeOrFail();

        if ( $addon->for_tenant !== $tenantType ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Addon not available for this tenant type.',
            ], 404 );
        }

        if ( AddonActivationService::isActiveForTenant( tenant()->id, $addon->id ) ) {
            return response()->json( [
                'status'  => 409,
                'message' => 'Addon is already active.',
            ], 409 );
        }

        if ( AddonActivationService::hasPendingForTenant( tenant()->id, $addon->id ) ) {
            return response()->json( [
                'status'  => 409,
                'message' => 'Addon payment is already pending.',
            ], 409 );
        }

        $price          = (float) $addon->price;
        $paymentMethod  = $request->input( 'payment_method' );
        $value          = $request->input( 'value' );
        $userId         = (int) auth()->id();

        if ( in_array( $addon->type, ['number', 'string'], true ) && blank( $value ) ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Value is required for this addon.',
            ], 422 );
        }

        // Free addon — activate immediately.
        if ( $price <= 0 ) {
            $installation = AddonActivationService::activate(
                $addon,
                tenant(),
                $userId,
                0,
                'free',
                uniqid(),
                $value,
                'active'
            );

            return response()->json( [
                'status'  => 200,
                'message' => 'Addon activated successfully.',
                'data'    => $installation,
            ] );
        }

        if ( ! in_array( $paymentMethod, ['aamarpay', 'my-wallet'], true ) ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Payment method is required for paid addons (aamarpay or my-wallet).',
            ], 422 );
        }

        if ( $paymentMethod === 'my-wallet' ) {
            $result = AddonActivationService::activateWithWallet(
                $addon,
                tenant(),
                $userId,
                $price,
                $value
            );

            if ( $result instanceof JsonResponse ) {
                return $result;
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Addon activated successfully.',
                'data'    => $result,
            ] );
        }

        try {
            return AddonActivationService::initiateGatewayPayment(
                $addon,
                tenant(),
                $userId,
                $price,
                $value,
                array_filter( [
                    'return_url' => $request->input( 'return_url' ),
                ] )
            );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => $e->getMessage(),
            ], 500 );
        }
    }

    private function tenantTypeOrFail(): string
    {
        if ( ! function_exists( 'tenant' ) || ! tenant() ) {
            abort( response()->json( [
                'status'  => 403,
                'message' => 'Tenant context is required.',
            ], 403 ) );
        }

        $tenantType = (string) tenant( 'type' );

        if ( ! in_array( $tenantType, ['dropshipper', 'merchant'], true ) ) {
            abort( response()->json( [
                'status'  => 422,
                'message' => 'Unsupported tenant type.',
            ], 422 ) );
        }

        return $tenantType;
    }
}
