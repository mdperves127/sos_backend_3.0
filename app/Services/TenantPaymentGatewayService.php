<?php

namespace App\Services;

use App\Models\PaymentGatewaySetting;
use App\Models\PaymentStore;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Per-tenant EPS / UddoktaPay credentials for storefront product checkout.
 * Platform .env credentials remain for wallet recharge and other central flows.
 */
class TenantPaymentGatewayService
{
    public const SECRET_PLACEHOLDERS = ['', '********', '****', '••••••••', '__KEEP__'];

    /**
     * @return list<string>
     */
    public static function secretKeysFor( string $gateway ): array
    {
        return match ( $gateway ) {
            PaymentGatewaySetting::GATEWAY_UDDOKTAPAY => ['api_key'],
            PaymentGatewaySetting::GATEWAY_EPS => ['password', 'hash_key'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function requiredKeysFor( string $gateway ): array
    {
        return match ( $gateway ) {
            PaymentGatewaySetting::GATEWAY_UDDOKTAPAY => ['api_key', 'base_url'],
            PaymentGatewaySetting::GATEWAY_EPS => [
                'mode',
                'username',
                'password',
                'hash_key',
                'merchant_id',
                'store_id',
            ],
            default => [],
        };
    }

    public static function find( string $gateway ): ?PaymentGatewaySetting
    {
        return PaymentGatewaySetting::query()->where( 'gateway', $gateway )->first();
    }

    public static function findForTenant( string|int $tenantId, string $gateway ): ?PaymentGatewaySetting
    {
        if ( function_exists( 'tenant' ) && tenant() && (string) tenant()->id === (string) $tenantId ) {
            return self::find( $gateway );
        }

        $tenant = Tenant::on( 'mysql' )->find( $tenantId );

        if ( ! $tenant ) {
            return null;
        }

        $connection = CrossTenantQueryService::connectionForTenant( $tenant );

        return PaymentGatewaySetting::on( $connection )
            ->where( 'gateway', $gateway )
            ->first();
    }

    /**
     * Safe dashboard / status payload (no decrypted secrets).
     *
     * @return array<string, mixed>
     */
    public static function publicStatus( string $gateway, ?PaymentGatewaySetting $setting = null ): array
    {
        $setting = $setting ?? self::find( $gateway );
        $creds   = is_array( $setting?->credentials ) ? $setting->credentials : [];
        $configured = self::credentialsAreComplete( $gateway, $creds );

        $base = [
            'gateway'     => $gateway,
            'enabled'     => (bool) ( $setting?->enabled ?? false ),
            'configured'  => $configured,
            'usable'      => $configured && (bool) ( $setting?->enabled ?? false ),
        ];

        if ( $gateway === PaymentGatewaySetting::GATEWAY_UDDOKTAPAY ) {
            return $base + [
                'base_url'       => (string) ( $creds['base_url'] ?? '' ),
                'api_key_masked' => self::mask( (string) ( $creds['api_key'] ?? '' ) ),
            ];
        }

        if ( $gateway === PaymentGatewaySetting::GATEWAY_EPS ) {
            return $base + [
                'mode'            => (string) ( $creds['mode'] ?? '' ),
                'username'        => (string) ( $creds['username'] ?? '' ),
                'merchant_id'     => (string) ( $creds['merchant_id'] ?? '' ),
                'store_id'        => (string) ( $creds['store_id'] ?? '' ),
                'transaction_type_id' => (int) ( $creds['transaction_type_id'] ?? 1 ),
                'password_masked' => self::mask( (string) ( $creds['password'] ?? '' ) ),
                'hash_key_masked' => self::mask( (string) ( $creds['hash_key'] ?? '' ) ),
            ];
        }

        return $base;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function publicStatusAll(): array
    {
        $out = [];

        foreach ( PaymentGatewaySetting::GATEWAYS as $gateway ) {
            $out[] = self::publicStatus( $gateway );
        }

        return $out;
    }

    /**
     * Merge incoming credentials with stored secrets (blank / masked keeps existing).
     *
     * @param  array<string, mixed>  $incoming
     */
    public static function upsert( string $gateway, bool $enabled, array $incoming ): PaymentGatewaySetting
    {
        $setting = self::find( $gateway ) ?? new PaymentGatewaySetting( ['gateway' => $gateway] );
        $existing = is_array( $setting->credentials ) ? $setting->credentials : [];
        $merged   = self::mergeCredentials( $gateway, $existing, $incoming );

        $setting->enabled     = $enabled;
        $setting->credentials = $merged;
        $setting->save();

        return $setting->fresh();
    }

    public static function disableAndClear( string $gateway ): void
    {
        $setting = self::find( $gateway );

        if ( ! $setting ) {
            return;
        }

        $setting->enabled     = false;
        $setting->credentials = [];
        $setting->save();
    }

    /**
     * Credentials required for storefront product checkout (no platform fallback).
     *
     * @return array<string, mixed>
     */
    public static function requireUsableCredentials( string $gateway, ?string $tenantId = null ): array
    {
        $tenantId = $tenantId
            ?? ( function_exists( 'tenant' ) && tenant() ? (string) tenant()->id : null );

        if ( ! $tenantId ) {
            throw new RuntimeException( 'Tenant context is required for product checkout payments.' );
        }

        $setting = self::findForTenant( $tenantId, $gateway );
        $creds   = is_array( $setting?->credentials ) ? $setting->credentials : [];

        if ( ! $setting || ! $setting->enabled ) {
            throw new RuntimeException(
                ucfirst( $gateway ) . ' is not enabled for this store. Configure it in payment gateway settings.'
            );
        }

        if ( ! self::credentialsAreComplete( $gateway, $creds ) ) {
            throw new RuntimeException(
                ucfirst( $gateway ) . ' credentials are incomplete. Update payment gateway settings.'
            );
        }

        return $creds;
    }

    /**
     * Apply storefront tenant credentials into gateway runtime for product checkout.
     */
    public static function applyForProductCheckout( string $gateway, ?string $tenantId = null ): void
    {
        $creds = self::requireUsableCredentials( $gateway, $tenantId );

        if ( $gateway === PaymentGatewaySetting::GATEWAY_EPS ) {
            EpsConfig::useRuntimeCredentials( $creds );
            EpsPaymentService::clearTokenCache();

            return;
        }

        if ( $gateway === PaymentGatewaySetting::GATEWAY_UDDOKTAPAY ) {
            UddoktaPayService::useRuntimeCredentials( $creds );
        }
    }

    /**
     * Resolve storefront tenant from a PaymentStore and apply EPS/UddoktaPay runtime credentials
     * when this is a product checkout payment. Platform credentials remain for recharge/etc.
     */
    public static function applyForPaymentStore( PaymentStore $payment ): void
    {
        $type = strtolower( (string) ( $payment->payment_type ?? '' ) );
        $isCheckout = in_array( $type, [
            'checkout',
            'product-checkout-success',
            'product checkout',
        ], true );

        if ( ! $isCheckout ) {
            EpsConfig::clearRuntimeCredentials();
            UddoktaPayService::clearRuntimeCredentials();
            EpsPaymentService::clearTokenCache();

            return;
        }

        $info = is_array( $payment->info ) ? $payment->info : [];
        $tenantId = (string) (
            $info['storefront_tenant_id']
            ?? $info['placing_tenant_id']
            ?? ''
        );

        if ( $tenantId === '' ) {
            throw new RuntimeException( 'Storefront tenant missing on product checkout payment.' );
        }

        $gateway = match ( (string) ( $payment->payment_gateway ?? '' ) ) {
            'uddoktapay' => PaymentGatewaySetting::GATEWAY_UDDOKTAPAY,
            default      => PaymentGatewaySetting::GATEWAY_EPS,
        };

        self::applyForProductCheckout( $gateway, $tenantId );
    }

    /**
     * Run callback with credentials appropriate for the payment transaction.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withCredentialsForTransaction( string $merchantTransactionId, callable $callback ): mixed
    {
        $payment = PaymentStore::on( 'mysql' )->where( 'trxid', $merchantTransactionId )->first();

        try {
            if ( $payment ) {
                self::applyForPaymentStore( $payment );
            } else {
                EpsConfig::clearRuntimeCredentials();
                UddoktaPayService::clearRuntimeCredentials();
                EpsPaymentService::clearTokenCache();
            }

            return $callback();
        } finally {
            EpsConfig::clearRuntimeCredentials();
            UddoktaPayService::clearRuntimeCredentials();
            EpsPaymentService::clearTokenCache();
        }
    }

    public static function mask( string $value ): ?string
    {
        $value = trim( $value );

        if ( $value === '' ) {
            return null;
        }

        $len = mb_strlen( $value );

        if ( $len <= 4 ) {
            return str_repeat( '*', $len );
        }

        return str_repeat( '*', max( 4, $len - 4 ) ) . mb_substr( $value, -4 );
    }

    public static function isSecretPlaceholder( mixed $value ): bool
    {
        if ( ! is_string( $value ) ) {
            return false;
        }

        $trimmed = trim( $value );

        if ( in_array( $trimmed, self::SECRET_PLACEHOLDERS, true ) ) {
            return true;
        }

        // Masked values returned by the API (****abcd).
        return (bool) preg_match( '/^\*{4,}.+$/', $trimmed );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function credentialsAreComplete( string $gateway, array $credentials ): bool
    {
        foreach ( self::requiredKeysFor( $gateway ) as $key ) {
            if ( blank( $credentials[$key] ?? null ) ) {
                return false;
            }
        }

        if ( $gateway === PaymentGatewaySetting::GATEWAY_EPS ) {
            $mode = strtolower( trim( (string) ( $credentials['mode'] ?? '' ) ) );

            if ( ! in_array( $mode, [EpsConfig::MODE_SANDBOX, EpsConfig::MODE_LIVE], true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergeCredentials( string $gateway, array $existing, array $incoming ): array
    {
        $allowed = match ( $gateway ) {
            PaymentGatewaySetting::GATEWAY_UDDOKTAPAY => ['api_key', 'base_url'],
            PaymentGatewaySetting::GATEWAY_EPS => [
                'mode',
                'username',
                'password',
                'hash_key',
                'merchant_id',
                'store_id',
                'transaction_type_id',
            ],
            default => [],
        };

        $merged = $existing;

        foreach ( $allowed as $key ) {
            if ( ! array_key_exists( $key, $incoming ) ) {
                continue;
            }

            $value = $incoming[$key];

            if ( in_array( $key, self::secretKeysFor( $gateway ), true )
                && self::isSecretPlaceholder( $value ) ) {
                continue;
            }

            if ( $key === 'transaction_type_id' ) {
                $merged[$key] = (int) ( $value ?: 1 );
                continue;
            }

            if ( $key === 'mode' ) {
                $merged[$key] = strtolower( trim( (string) $value ) );
                continue;
            }

            if ( $key === 'base_url' ) {
                $merged[$key] = rtrim( trim( (string) $value ), '/' );
                continue;
            }

            $merged[$key] = is_string( $value ) ? trim( $value ) : $value;
        }

        return $merged;
    }

    /**
     * Assert PaymentStore belongs to the current tenancy route tenant.
     */
    public static function assertPaymentBelongsToCurrentTenant( PaymentStore $payment ): void
    {
        $current = function_exists( 'tenant' ) && tenant() ? (string) tenant()->id : null;

        if ( ! $current ) {
            return;
        }

        $info = is_array( $payment->info ) ? $payment->info : [];
        $storefront = (string) (
            $info['storefront_tenant_id']
            ?? $info['placing_tenant_id']
            ?? ''
        );

        if ( $storefront !== '' && $storefront !== $current ) {
            throw new RuntimeException( 'Payment does not belong to this tenant.' );
        }
    }

    /**
     * Smoke-check that APP_KEY can encrypt/decrypt (used in tests).
     */
    public static function encryptRoundTrip( string $plain ): string
    {
        try {
            return Crypt::decryptString( Crypt::encryptString( $plain ) );
        } catch ( Throwable $e ) {
            throw new RuntimeException( 'Credential encryption failed: ' . $e->getMessage(), 0, $e );
        }
    }
}
