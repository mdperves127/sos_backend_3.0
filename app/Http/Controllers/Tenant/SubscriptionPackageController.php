<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

class SubscriptionPackageController extends Controller
{
    /**
     * List custom packages (is_custom = true) for current tenant type.
     * Same packages created by admin POST /api/admin/custom-package.
     */
    public function index(): JsonResponse
    {
        if ( ! function_exists( 'tenant' ) || ! tenant() ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Tenant context is required.',
            ], 403 );
        }

        $packages = Subscription::on( 'mysql' )
            ->where( 'is_custom', true )
            ->where( 'subscription_user_type', $this->subscriptionUserType() )
            ->latest( 'id' )
            ->get();

        return response()->json( [
            'status'  => 200,
            'message' => 'Custom packages fetched successfully.',
            'data'    => $packages,
        ] );
    }

    /**
     * View one custom package.
     */
    public function show( int|string $id ): JsonResponse
    {
        if ( ! function_exists( 'tenant' ) || ! tenant() ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Tenant context is required.',
            ], 403 );
        }

        $package = Subscription::on( 'mysql' )
            ->where( 'id', $id )
            ->where( 'is_custom', true )
            ->where( 'subscription_user_type', $this->subscriptionUserType() )
            ->first();

        if ( ! $package ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Custom package not found.',
            ], 404 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Custom package fetched successfully.',
            'data'    => $package,
        ] );
    }

    private function subscriptionUserType(): string
    {
        return ( tenant( 'type' ) === 'dropshipper' ) ? 'affiliate' : 'vendor';
    }
}
