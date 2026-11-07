<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform fee on dropshipper settlements.
 *
 * Global mode (central settings.dropship_fee_type):
 *  - none:       no fee
 *  - fixed:      settings.dropship_fee_value per delivered order
 *  - percentage: settings.dropship_fee_value % of the settled commission
 *  - plan:       the dropshipper's active plan (subscriptions.dropship_fee_*) decides;
 *                no active plan or no plan fee configured => no fee
 *
 * The fee never exceeds the gross amount being settled, so it is only ever
 * withheld from the platform-held commission (no external debit).
 */
class PlatformFeeService {

    public const TYPE_NONE       = 'none';
    public const TYPE_FIXED      = 'fixed';
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_PLAN       = 'plan';

    public const GLOBAL_TYPES = [self::TYPE_NONE, self::TYPE_FIXED, self::TYPE_PERCENTAGE, self::TYPE_PLAN];
    public const PLAN_TYPES   = [self::TYPE_FIXED, self::TYPE_PERCENTAGE];

    /**
     * @return array{type: string, rate: float, amount: float, source: string, gross: float, net: float}
     */
    public static function calculate( string|int|null $dropshipperTenantId, float $grossAmount ): array {
        $gross = round( max( 0, $grossAmount ), 2 );

        [$type, $rate, $source] = static::resolveRule( $dropshipperTenantId );

        $amount = match ( $type ) {
            self::TYPE_FIXED      => $rate,
            self::TYPE_PERCENTAGE => round( $gross * min( $rate, 100 ) / 100, 2 ),
            default               => 0.0,
        };

        $amount = round( min( max( 0, $amount ), $gross ), 2 );

        return [
            'type'   => $type,
            'rate'   => $rate,
            'amount' => $amount,
            'source' => $source,
            'gross'  => $gross,
            'net'    => round( $gross - $amount, 2 ),
        ];
    }

    /**
     * @return array{0: string, 1: float, 2: string}
     */
    protected static function resolveRule( string|int|null $dropshipperTenantId ): array {
        $settings = self::globalSettings();
        $mode     = in_array( $settings['type'], self::GLOBAL_TYPES, true ) ? $settings['type'] : self::TYPE_NONE;

        if ( $mode === self::TYPE_FIXED || $mode === self::TYPE_PERCENTAGE ) {
            return [$mode, $settings['value'], 'global'];
        }

        if ( $mode === self::TYPE_PLAN && $dropshipperTenantId ) {
            $plan = self::activePlan( $dropshipperTenantId );
            if ( $plan && in_array( $plan->dropship_fee_type ?? null, self::PLAN_TYPES, true ) ) {
                return [$plan->dropship_fee_type, round( max( 0, (float) $plan->dropship_fee_value ), 2 ), 'plan:' . $plan->id];
            }

            return [self::TYPE_NONE, 0.0, 'plan:none'];
        }

        return [self::TYPE_NONE, 0.0, 'global'];
    }

    /**
     * @return array{type: string, value: float}
     */
    public static function globalSettings(): array {
        if ( ! Schema::connection( 'mysql' )->hasColumn( 'settings', 'dropship_fee_type' ) ) {
            return ['type' => self::TYPE_NONE, 'value' => 0.0];
        }

        $row = DB::connection( 'mysql' )
            ->table( 'settings' )
            ->whereNull( 'deleted_at' )
            ->orderBy( 'id' )
            ->first( ['dropship_fee_type', 'dropship_fee_value'] );

        return [
            'type'  => (string) ( $row->dropship_fee_type ?? self::TYPE_NONE ),
            'value' => round( max( 0, (float) ( $row->dropship_fee_value ?? 0 ) ), 2 ),
        ];
    }

    protected static function activePlan( string|int $dropshipperTenantId ): ?Subscription {
        if ( ! Schema::connection( 'mysql' )->hasColumn( 'subscriptions', 'dropship_fee_type' ) ) {
            return null;
        }

        $userSubscription = UserSubscription::on( 'mysql' )
            ->where( 'tenant_id', $dropshipperTenantId )
            ->latest( 'id' )
            ->first();

        if ( ! $userSubscription ) {
            return null;
        }

        if ( $userSubscription->expire_date && now()->greaterThan( $userSubscription->expire_date ) ) {
            return null;
        }

        return Subscription::on( 'mysql' )->find( $userSubscription->subscription_id );
    }
}
