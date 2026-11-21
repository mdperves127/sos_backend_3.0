<?php

namespace App\Services\WooCommerce;

use App\Models\WoocommerceCredential;
use App\Models\WoocommerceSyncLog;

class WooCommerceSyncLogger {
    public static function log(
        ?WoocommerceCredential $credential,
        string $syncType,
        string $direction,
        string $status,
        ?string $message = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $externalId = null,
        ?array $before = null,
        ?array $after = null,
        int $retryCount = 0
    ): WoocommerceSyncLog {
        return WoocommerceSyncLog::create( [
            'credential_id'  => $credential?->id,
            'sync_type'      => $syncType,
            'direction'      => $direction,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'external_id'    => $externalId,
            'status'         => $status,
            'message'        => $message,
            'payload_before' => self::safePayload( $before ),
            'payload_after'  => self::safePayload( $after ),
            'retry_count'    => $retryCount,
        ] );
    }

    public static function markCredentialSynced( WoocommerceCredential $credential, bool $success, ?string $error = null ): void {
        $credential->last_synced_at = now();
        if ( $success ) {
            $credential->last_success_at = now();
            $credential->last_error      = null;
            if ( $credential->status !== WoocommerceCredential::STATUS_CONNECTED ) {
                $credential->status             = WoocommerceCredential::STATUS_CONNECTED;
                $credential->connection_message = 'Connected';
            }
        } else {
            $credential->last_error = $error;
        }
        $credential->save();
    }

    private static function safePayload( ?array $payload ): ?array {
        if ( $payload === null ) {
            return null;
        }

        unset( $payload['wc_secret'], $payload['consumer_secret'], $payload['webhook_secret'] );

        return $payload;
    }
}
