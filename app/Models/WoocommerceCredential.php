<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WoocommerceCredential extends Model {
    use HasFactory, SoftDeletes;

    // Use the active default connection (HTTP middleware sets `tenant`;
    // queued jobs bind `tenant` or switch default). Do not hard-pin here.
    protected $guarded = [];

    protected $hidden = [
        'wc_secret',
        'webhook_secret',
    ];

    protected $casts = [
        // Keep sync toggles uncast: missing columns are null and must default to ON.
        'last_synced_at'  => 'datetime',
        'last_success_at' => 'datetime',
    ];

    public const DIRECTION_WP_TO_AFFSELL = 'wp_to_affsell';
    public const DIRECTION_AFFSELL_TO_WP = 'affsell_to_wp';
    public const DIRECTION_TWO_WAY       = 'two_way';

    public const STATUS_CONNECTED    = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_ERROR        = 'error';

    public function syncLogs() {
        return $this->hasMany( WoocommerceSyncLog::class, 'credential_id' );
    }

    public function allowsDirection( string $direction ): bool {
        $mode = $this->sync_direction ?: self::DIRECTION_TWO_WAY;

        return $mode === self::DIRECTION_TWO_WAY || $mode === $direction;
    }

    public function canSyncProducts( string $direction ): bool {
        // Null = column not migrated yet → treat as enabled (legacy rows).
        return ( $this->is_active ?? true )
            && ( $this->product_sync ?? true )
            && $this->allowsDirection( $direction );
    }

    public function canSyncOrders( string $direction ): bool {
        return ( $this->is_active ?? true )
            && ( $this->order_sync ?? true )
            && $this->allowsDirection( $direction );
    }

    public function canSyncStock( string $direction ): bool {
        return ( $this->is_active ?? true )
            && ( $this->stock_sync ?? true )
            && $this->allowsDirection( $direction );
    }

    public function normalizedUrl(): string {
        return rtrim( (string) $this->wc_url, '/' );
    }

    /** Safe payload for merchant dashboard (never includes secrets). */
    public function toPublicArray(): array {
        return [
            'id'                  => $this->id,
            'wc_url'              => $this->wc_url,
            'wc_key_masked'       => $this->mask( $this->wc_key ),
            'status'              => $this->status ?: self::STATUS_DISCONNECTED,
            'connection_message'  => $this->connection_message,
            'sync_direction'      => $this->sync_direction ?: self::DIRECTION_TWO_WAY,
            'product_sync'        => (bool) ( $this->product_sync ?? true ),
            'order_sync'          => (bool) ( $this->order_sync ?? true ),
            'stock_sync'          => (bool) ( $this->stock_sync ?? true ),
            'auto_sync'           => (bool) ( $this->auto_sync ?? true ),
            'is_active'           => (bool) ( $this->is_active ?? true ),
            'last_synced_at'      => optional( $this->last_synced_at )->toIso8601String(),
            'last_success_at'     => optional( $this->last_success_at )->toIso8601String(),
            'last_error'          => $this->last_error,
            'has_webhook_secret'  => ! empty( $this->webhook_secret ),
            'created_at'          => optional( $this->created_at )->toIso8601String(),
            'updated_at'          => optional( $this->updated_at )->toIso8601String(),
        ];
    }

    private function mask( ?string $value ): ?string {
        if ( ! $value ) {
            return null;
        }
        $len = strlen( $value );
        if ( $len <= 8 ) {
            return str_repeat( '*', $len );
        }

        return substr( $value, 0, 4 ) . str_repeat( '*', max( 0, $len - 8 ) ) . substr( $value, -4 );
    }
}
