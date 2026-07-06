<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Order extends Model {
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'variants' => 'array',
    ];

    /**
     * Parse variants from DB string, cast array, or legacy double-encoded JSON.
     */
    public static function normalizeVariants( mixed $variants ): array {
        if ( $variants === null || $variants === '' ) {
            return [];
        }

        if ( is_array( $variants ) ) {
            return $variants;
        }

        if ( !is_string( $variants ) ) {
            return [];
        }

        $decoded = json_decode( $variants, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [];
        }

        if ( is_string( $decoded ) ) {
            $decoded = json_decode( $decoded, true );
        }

        return is_array( $decoded ) ? $decoded : [];
    }

    function affiliator() {
        return $this->belongsTo( User::class, 'affiliator_id', 'id' );
    }
    function product() {
        return $this->belongsTo( Product::class, 'product_id', 'id' );
    }

    function orderDetails() {
        return $this->hasMany( OrderDetails::class )->with( 'size', 'unit', 'color', 'product' );
    }

    function vendor() {
        return $this->belongsTo( User::class, 'vendor_id', 'id' );
    }

    static function searchProduct() {
        return self::when( request( 'search' ), function ( $q, $search ) {
            $q->where( function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $subQuery ) use ( $search ) {
                    $subQuery->where( 'name', 'like', "%{$search}%" );
                } )->orWhere( 'order_id', 'like', "%{$search}%" );
            } );
        } );
    }

    public static function boot() {
        parent::boot();

        static::creating( function ( $order ) {
            if ( ! empty( $order->order_id ) ) {
                return;
            }

            $order->order_id = self::generateTransactionId( $order->getConnectionName() );
        } );
    }

    public static function generateTransactionId( ?string $connectionName = null ) {
        $text = 'OR';

        do {
            $number        = str_pad( mt_rand( 1, 999999 ), 6, '0', STR_PAD_LEFT );
            $transactionId = $text . $number;

            $exists = $connectionName
                ? DB::connection( $connectionName )->table( 'orders' )->where( 'order_id', $transactionId )->exists()
                : self::query()->where( 'order_id', $transactionId )->exists();
        } while ( $exists );

        return $transactionId;
    }

    function productrating() {
        return $this->hasOne( ProductRating::class, 'order_id' );
    }

    function productratings() {
        return $this->hasMany( ProductRating::class, 'order_id' );
    }

    public function pickupArea() {
        return $this->belongsTo( DeliveryAndPickupAddress::class, 'pickup_area' );
    }

    public function deliveryArea() {
        return $this->belongsTo( DeliveryAndPickupAddress::class, 'delivery_area' );
    }
}
