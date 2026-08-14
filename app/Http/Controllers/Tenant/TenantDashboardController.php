<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\EpsPaymentCompletionService;
use Illuminate\Http\JsonResponse;

class TenantDashboardController extends Controller {

    public function statistics(): JsonResponse {
        if ( !function_exists( 'tenant' ) ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Tenant context is required.',
            ], 403 );
        }

        // Credit EPS recharges that returned to SPA dashboard (no Laravel callback).
        try {
            app( EpsPaymentCompletionService::class )
                ->completePendingForTenant( (string) tenant( 'id' ) );
        } catch ( \Throwable $e ) {
            // Never block dashboard for payment recovery.
        }

        return match ( tenant( 'type' ) ) {
            'merchant'    => app( MerchantDashboardController::class )->statistics(),
            'dropshipper' => app( DropshipperDashboardController::class )->statistics(),
            default       => response()->json( [
                'status'  => 403,
                'message' => 'Dashboard statistics are not available for this tenant type.',
            ], 403 ),
        };
    }
}
