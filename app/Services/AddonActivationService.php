<?php

namespace App\Services;

use App\Helper\RedirectHelper;
use App\Models\Addon;
use App\Models\PaymentStore;
use App\Models\Tenant;
use App\Models\TenantInstalledAddon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AddonActivationService
{
    /**
     * Resolve stored value from request based on addon value type.
     */
    public static function resolveValue( Addon $addon, ?string $requestedValue ): string
    {
        return (string) ( $requestedValue ?? '' );
    }

    public static function isActiveForTenant( string $tenantId, int $addonId ): bool
    {
        return TenantInstalledAddon::on( 'mysql' )
            ->where( 'tenant_id', $tenantId )
            ->where( 'addon_id', $addonId )
            ->where( 'status', 'active' )
            ->exists();
    }

    public static function hasPendingForTenant( string $tenantId, int $addonId ): bool
    {
        return TenantInstalledAddon::on( 'mysql' )
            ->where( 'tenant_id', $tenantId )
            ->where( 'addon_id', $addonId )
            ->where( 'status', 'pending' )
            ->exists();
    }

    public static function findInactiveForTenant( string $tenantId, int $addonId ): ?TenantInstalledAddon
    {
        return TenantInstalledAddon::on( 'mysql' )
            ->where( 'tenant_id', $tenantId )
            ->where( 'addon_id', $addonId )
            ->where( 'status', 'inactive' )
            ->latest( 'id' )
            ->first();
    }

    public static function findActiveForTenant( string $tenantId, int $addonId ): ?TenantInstalledAddon
    {
        return TenantInstalledAddon::on( 'mysql' )
            ->where( 'tenant_id', $tenantId )
            ->where( 'addon_id', $addonId )
            ->where( 'status', 'active' )
            ->first();
    }

    /**
     * Mark an active addon as inactive (keeps installation record).
     */
    public static function deactivate( string $tenantId, int $addonId ): TenantInstalledAddon
    {
        $installation = self::findActiveForTenant( $tenantId, $addonId );

        if ( ! $installation ) {
            throw new RuntimeException( 'Active addon installation not found.' );
        }

        $installation->update( ['status' => 'inactive'] );

        return $installation->fresh();
    }

    /**
     * Re-enable a previously deactivated addon without charging again.
     */
    public static function reactivateFromInactive(
        TenantInstalledAddon $installation,
        ?int $userId,
        ?string $value
    ): TenantInstalledAddon {
        $installation->update( [
            'user_id'      => $userId,
            'value'        => $value ?? $installation->value,
            'status'       => 'active',
            'activated_at' => now(),
        ] );

        return $installation->fresh();
    }

    /**
     * Activate addon immediately (free or after wallet / gateway payment).
     */
    public static function activate(
        Addon $addon,
        Tenant $tenant,
        ?int $userId,
        float $pricePaid,
        string $paymentMethod,
        ?string $trxid,
        ?string $value,
        string $status = 'active'
    ): TenantInstalledAddon {
        $resolvedValue = self::resolveValue( $addon, $value );

        $existing = TenantInstalledAddon::on( 'mysql' )
            ->where( 'tenant_id', $tenant->id )
            ->where( 'addon_id', $addon->id )
            ->whereIn( 'status', ['inactive', 'cancelled'] )
            ->latest( 'id' )
            ->first();

        if ( $existing ) {
            $existing->update( [
                'user_id'        => $userId,
                'addon_name'     => $addon->name,
                'addon_type'     => $addon->addon_type,
                'value'          => $resolvedValue,
                'price_paid'     => $pricePaid > 0 ? $pricePaid : $existing->price_paid,
                'payment_method' => $paymentMethod,
                'trxid'          => $trxid,
                'status'         => $status,
                'activated_at'   => $status === 'active' ? now() : null,
            ] );

            return $existing->fresh();
        }

        $installation = TenantInstalledAddon::on( 'mysql' )->create( [
            'tenant_id'      => $tenant->id,
            'addon_id'       => $addon->id,
            'user_id'        => $userId,
            'addon_name'     => $addon->name,
            'addon_type'     => $addon->addon_type,
            'value_type'     => 'string',
            'value'          => $resolvedValue,
            'price_paid'     => $pricePaid,
            'payment_method' => $paymentMethod,
            'trxid'          => $trxid,
            'status'         => $status,
            'activated_at'   => $status === 'active' ? now() : null,
        ] );

        return $installation;
    }

    public static function initiateGatewayPayment(
        Addon $addon,
        Tenant $tenant,
        int $userId,
        float $amount,
        ?string $value,
        array $extra = []
    ) {
        if ( self::hasPendingForTenant( $tenant->id, $addon->id ) ) {
            throw new RuntimeException( 'This addon already has a pending payment.' );
        }

        $trxid = uniqid();
        $info  = RedirectHelper::appendPaymentReturnUrl( array_merge( [
            'addon_id'  => $addon->id,
            'tenant_id' => $tenant->id,
            'user_id'   => $userId,
            'amount'    => $amount,
            'value'     => self::resolveValue( $addon, $value ),
        ], $extra ) );

        PaymentStore::on( 'mysql' )->create( [
            'payment_gateway'         => 'aamarpay',
            'trxid'                   => $trxid,
            'status'                  => 'pending',
            'payment_type'            => 'addon',
            'info'                    => $info,
            'customer_requirement_id' => 0,
        ] );

        self::activate(
            $addon,
            $tenant,
            $userId,
            $amount,
            'Aamarpay',
            $trxid,
            $info['value'] ?? null,
            'pending'
        );

        $successUrl = EpsPaymentService::paymentSuccessUrl( 'addon-success' );

        return AamarPayService::gateway( $amount, $trxid, 'addon', $successUrl, 'tenant' );
    }

    public static function activateWithWallet(
        Addon $addon,
        Tenant $tenant,
        int $userId,
        float $amount,
        ?string $value
    ): TenantInstalledAddon|JsonResponse {
        return DB::connection( 'mysql' )->transaction( function () use ( $addon, $tenant, $userId, $amount, $value ) {
            $row = DB::connection( 'mysql' )
                ->table( 'tenants' )
                ->where( 'id', $tenant->id )
                ->lockForUpdate()
                ->first( ['id', 'balance'] );

            if ( ! $row ) {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Tenant wallet not found.',
                ], 404 );
            }

            $balance = (float) convertfloat( (string) ( $row->balance ?? 0 ) );
            if ( $balance < $amount ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Not enough wallet balance.',
                ], 400 );
            }

            DB::connection( 'mysql' )
                ->table( 'tenants' )
                ->where( 'id', $tenant->id )
                ->update( [
                    'balance'    => $balance - $amount,
                    'updated_at' => now(),
                ] );

            $trxid = uniqid();

            PaymentHistoryService::store(
                $trxid,
                $amount,
                'My wallet',
                'Addon',
                '-',
                '',
                $tenant->id,
                [
                    'entity_type' => 'tenant',
                    'tenant_id'   => $tenant->id,
                    'user_id'     => $userId,
                ]
            );

            return self::activate(
                $addon,
                $tenant,
                $userId,
                $amount,
                'My wallet',
                $trxid,
                $value,
                'active'
            );
        } );
    }

    public static function completeFromPayment( PaymentStore $payment, array $verification ): string
    {
        $info   = is_array( $payment->info ) ? $payment->info : [];
        $amount = (float) ( $info['amount'] ?? $verification['TotalAmount'] ?? $verification['StoreAmount'] ?? 0 );
        $tenantId = (string) ( $info['tenant_id'] ?? '' );
        $addonId  = (int) ( $info['addon_id'] ?? 0 );
        $userId   = $info['user_id'] ?? null;

        if ( $tenantId === '' || $addonId <= 0 ) {
            throw new RuntimeException( 'Invalid addon payment info.' );
        }

        $tenant = Tenant::on( 'mysql' )->find( $tenantId );
        $addon  = Addon::on( 'mysql' )->find( $addonId );

        if ( ! $tenant || ! $addon ) {
            throw new RuntimeException( 'Tenant or addon not found for payment completion.' );
        }

        $pending = TenantInstalledAddon::on( 'mysql' )
            ->where( 'tenant_id', $tenantId )
            ->where( 'addon_id', $addonId )
            ->where( 'trxid', $payment->trxid )
            ->where( 'status', 'pending' )
            ->first();

        if ( $pending ) {
            $pending->update( [
                'status'         => 'active',
                'price_paid'     => $amount,
                'payment_method' => 'Aamarpay',
                'activated_at'   => now(),
            ] );
        } elseif ( ! self::isActiveForTenant( $tenantId, $addonId ) ) {
            self::activate(
                $addon,
                $tenant,
                $userId ? (int) $userId : null,
                $amount,
                'Aamarpay',
                $payment->trxid,
                $info['value'] ?? null,
                'active'
            );
        }

        PaymentHistoryService::store(
            $payment->trxid,
            $amount,
            'Aamarpay',
            'Addon',
            '-',
            '',
            $tenantId,
            [
                'entity_type' => 'tenant',
                'tenant_id'   => $tenantId,
                'user_id'     => $userId,
            ]
        );

        $payment->update( ['status' => 'completed'] );

        return RedirectHelper::getPaymentRedirectUrl( $tenantId, $info['return_url'] ?? null )
            . 'dashboard?message=' . urlencode( 'Addon activated successfully' );
    }
}
