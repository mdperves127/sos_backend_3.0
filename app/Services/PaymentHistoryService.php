<?php

namespace App\Services;

use App\Models\PaymentHistory;
use App\Models\User;
use App\Models\Tenant;

/**
 * Class PaymentHistoryService.
 */
class PaymentHistoryService
{
    static function store($trxid, $amount, $payment_method, $transition_type, $balance_type, $coupon, $entityId = null, array $context = []) {
        $entityType = $context['entity_type'] ?? null;
        $tenantId = array_key_exists( 'tenant_id', $context ) ? $context['tenant_id'] : null;
        $userId = array_key_exists( 'user_id', $context ) ? $context['user_id'] : null;

        if ( $entityType === 'tenant' ) {
            $tenantId = $tenantId ?? $entityId;
            $userId = $userId ?? ( auth()->check() ? auth()->id() : null );
        } elseif ( $entityType === 'user' ) {
            $userId = $userId ?? $entityId;
        } elseif ( function_exists( 'tenant' ) && tenant() ) {
            // In tenant context, payment histories belong to the central tenant record by default.
            $tenantId = $tenantId ?? tenant()->id;
            $userId = $userId ?? ( auth()->check() ? auth()->id() : ( $entityId ?: null ) );
        } else {
            $tenant = $entityId ? Tenant::on( 'mysql' )->find( $entityId ) : null;
            $user = $entityId ? User::on( 'mysql' )->find( $entityId ) : null;

            if ( $tenant && ! $user ) {
                $tenantId = $tenantId ?? $tenant->id;
                $userId   = $userId ?? ( auth()->check() ? auth()->id() : null );
            } else {
                $userId = $userId ?? $entityId;
            }
        }

        if ( $tenantId && ( $userId === null || $userId === 0 || $userId === '0' ) ) {
            $userId = auth()->check() ? auth()->id() : $userId;
        }

        if ( ! $tenantId && ( $userId === 0 || $userId === '0' ) ) {
            $userId = null;
        }

        return PaymentHistory::on( 'mysql' )->create( [
            'trxid'           => $trxid,
            'amount'          => $amount,
            'payment_method'  => $payment_method,
            'transition_type' => $transition_type,
            'balance_type'    => $balance_type,
            'coupon'          => $coupon,
            'user_id'         => $userId,
            'tenant_id'       => $tenantId,
        ] );
    }
}
