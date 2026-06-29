<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CrossTenantQueryService;

class BalanceController extends Controller
{
    private function dropshipperTenantId(): ?string {
        return function_exists( 'tenant' ) && tenant() ? (string) tenant()->id : null;
    }

    private function sumAffiliateCommission( array $statuses ): float {
        $tenantId = $this->dropshipperTenantId();
        $userId   = auth()->id();

        if ( !$tenantId || !$userId ) {
            return 0.0;
        }

        $orders = CrossTenantQueryService::queryAllTenants(
            Order::class,
            function ( $query ) use ( $tenantId, $userId, $statuses ) {
                $query->where( 'tenant_id', $tenantId )
                    ->where( 'affiliator_id', $userId )
                    ->whereIn( 'status', $statuses );
            }
        );

        return (float) collect( $orders )->sum( fn ( $order ) => (float) ( $order->afi_amount ?? 0 ) );
    }

    function PendingBalance() {
        $balance = $this->sumAffiliateCommission( [
            Status::Pending->value,
            Status::Hold->value,
            Status::Progress->value,
            Status::Processing->value,
            'received',
            Status::Ready->value,
        ] );

        return response()->json( $balance );
    }

    function ActiveBalance() {
        if ( function_exists( 'tenant' ) && tenant() ) {
            return response()->json( (float) ( tenant()->balance ?? 0 ) );
        }

        $balance = $this->sumAffiliateCommission( [
            Status::Delivered->value,
            Status::Completed->value,
        ] );

        return response()->json( $balance );
    }
}
