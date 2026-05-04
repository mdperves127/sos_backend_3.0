<?php

namespace App\Http\Controllers\Tenant\Concerns;

use App\Models\ProductDetails;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

trait ResolvesTenantChatAccess {
    /**
     * Tenant store chat uses central `user_subscriptions` for the tenant plus `chat_access` on the row or plan.
     */
    private function tenantChatSubscription(): ?UserSubscription {
        return UserSubscription::on( 'mysql' )
            ->where( 'tenant_id', tenant()->id )
            ->with( 'subscription' )
            ->first();
    }

    private function tenantHasChatAccess( ?UserSubscription $sub ): bool {
        if ( !$sub ) {
            return false;
        }
        if ( $sub->chat_access === 'yes' ) {
            return true;
        }
        $plan = $sub->subscription;

        return $plan && $plan->chat_access === 'yes';
    }

    private function chatAccessDeniedResponse(): JsonResponse {
        return response()->json( [
            'status'  => 401,
            'message' => 'Oops! It seems you are not eligible to access this feature. Please contact the administrator for assistance.',
        ], 401 );
    }

    private function chatPlanDeniedResponse(): JsonResponse {
        return response()->json( [
            'status'  => 401,
            'message' => 'Oops! This service is not available with your current subscription. Please contact the administrator for assistance.',
        ], 401 );
    }

    private function tenantKey(): string {
        return (string) tenant()->id;
    }

    /**
     * User ids that participate in this tenant's product catalog (scoped by {@see ProductDetails::$tenant_id} only).
     */
    private function tenantCatalogParticipantUserIds(): Collection {
        $tid = $this->tenantKey();

        return ProductDetails::query()
            ->where( 'status', 1 )
            ->where( 'tenant_id', $tid )
            ->get( ['user_id', 'vendor_id'] )
            ->flatMap( static fn ( $row ) => [(int) $row->user_id, (int) $row->vendor_id] )
            ->filter( static fn ( int $id ) => $id > 0 )
            ->unique()
            ->values();
    }

    /**
     * Both users appear on active product rows for this tenant (no vendor↔affiliate pairing query).
     */
    private function tenantUsersAreChatPartners( int $a, int $b ): bool {
        if ( $a === $b || $a <= 0 || $b <= 0 ) {
            return false;
        }

        $ids = $this->tenantCatalogParticipantUserIds();

        return $ids->contains( $a ) && $ids->contains( $b );
    }
}

