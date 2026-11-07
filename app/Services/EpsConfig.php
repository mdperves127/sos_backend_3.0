<?php

namespace App\Services;

class EpsConfig
{
    public const MODE_SANDBOX = 'sandbox';

    public const MODE_LIVE = 'live';

    /**
     * Request-scoped tenant credentials for product checkout (never platform fallback).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $runtimeCredentials = null;

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function useRuntimeCredentials( array $credentials ): void
    {
        self::$runtimeCredentials = $credentials;
    }

    public static function clearRuntimeCredentials(): void
    {
        self::$runtimeCredentials = null;
    }

    public static function hasRuntimeCredentials(): bool
    {
        return is_array( self::$runtimeCredentials ) && self::$runtimeCredentials !== [];
    }

    /**
     * Active EPS mode: sandbox or live.
     */
    public static function mode(): string
    {
        if ( self::hasRuntimeCredentials() ) {
            $runtimeMode = strtolower( trim( (string) ( self::$runtimeCredentials['mode'] ?? '' ) ) );

            if ( in_array( $runtimeMode, [self::MODE_SANDBOX, self::MODE_LIVE], true ) ) {
                return $runtimeMode;
            }
        }

        $mode = strtolower( trim( (string) config( 'services.eps.mode', '' ) ) );

        if ( in_array( $mode, [self::MODE_SANDBOX, self::MODE_LIVE], true ) ) {
            return $mode;
        }

        $legacy = config( 'services.eps.legacy_sandbox' );

        if ( $legacy !== null && $legacy !== '' ) {
            return filter_var( $legacy, FILTER_VALIDATE_BOOLEAN ) ? self::MODE_SANDBOX : self::MODE_LIVE;
        }

        return self::MODE_LIVE;
    }

    public static function isSandbox(): bool
    {
        return self::mode() === self::MODE_SANDBOX;
    }

    public static function isLive(): bool
    {
        return self::mode() === self::MODE_LIVE;
    }

    public static function apiBaseUrl(): string
    {
        return self::isSandbox()
            ? 'https://sandboxpgapi.eps.com.bd/v1'
            : 'https://pgapi.eps.com.bd/v1';
    }

    /**
     * Merchant portal BaseUrl (must match EPS dashboard for the active mode).
     */
    public static function merchantBaseUrl(): string
    {
        $modeSpecific = trim( (string) config( 'services.eps.base_urls.' . self::mode(), '' ) );

        if ( $modeSpecific !== '' ) {
            return rtrim( $modeSpecific, '/' );
        }

        return rtrim( (string) config( 'services.eps.base_url', 'https://affsell.com' ), '/' );
    }

    public static function get( string $key ): mixed
    {
        if ( self::hasRuntimeCredentials() && array_key_exists( $key, self::$runtimeCredentials ) ) {
            return self::$runtimeCredentials[$key];
        }

        // Product-checkout runtime mode must not leak into platform .env credentials.
        if ( self::hasRuntimeCredentials() ) {
            return null;
        }

        $mode  = self::mode();
        $value = config( "services.eps.credentials.{$mode}.{$key}" );

        if ( ! blank( $value ) ) {
            return $value;
        }

        return config( "services.eps.credentials.legacy.{$key}" );
    }

    public static function isConfigured(): bool
    {
        foreach ( self::requiredCredentialKeys() as $key ) {
            if ( blank( self::get( $key ) ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function missingCredentialEnvKeys(): array
    {
        $mode   = self::mode();
        $prefix = strtoupper( $mode );
        $missing = [];

        foreach ( self::credentialEnvNames() as $configKey => $envSuffix ) {
            if ( blank( self::get( $configKey ) ) ) {
                $missing[] = "EPS_{$prefix}_{$envSuffix} (or legacy EPS_{$envSuffix})";
            }
        }

        return $missing;
    }

    /**
     * Safe metadata for APIs / debugging (no secrets).
     *
     * @return array<string, mixed>
     */
    public static function publicStatus(): array
    {
        return [
            'gateway'    => 'eps',
            'mode'         => self::mode(),
            'sandbox'      => self::isSandbox(),
            'configured'   => self::isConfigured(),
            'api_base'     => self::apiBaseUrl(),
            'merchant_base'=> self::merchantBaseUrl(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function requiredCredentialKeys(): array
    {
        return array_keys( self::credentialEnvNames() );
    }

    /**
     * @return array<string, string>
     */
    private static function credentialEnvNames(): array
    {
        return [
            'username'    => 'USERNAME',
            'password'    => 'PASSWORD',
            'hash_key'    => 'HASH_KEY',
            'merchant_id' => 'MERCHANT_ID',
            'store_id'    => 'STORE_ID',
        ];
    }
}
