<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model {
    use HasFactory;
    protected $guarded = [];

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
            $order->order_id = self::generateTransactionId();
        } );
    }

    public static function generateTransactionId() {
        $text   = 'OR'; // Prefix for the transaction ID
        $number = str_pad( mt_rand( 1, 999999 ), 6, '0', STR_PAD_LEFT ); // Random 6-digit number

        $transactionId = $text . $number;

        // Check if the generated transaction ID already exists in the database
        $existingTransaction = Order::where( 'order_id', $transactionId )->first();
        if ( $existingTransaction ) {
            // If the transaction ID exists, regenerate it recursively until a unique one is found
            return self::generateTransactionId();
        }

        return $transactionId;
    }

    function productrating() {
        return $this->hasMany( ProductRating::class, 'order_id' );
    }

    public function pickupArea() {
        return $this->belongsTo( DeliveryAndPickupAddress::class, 'pickup_area' );
    }

    public function deliveryArea() {
        return $this->belongsTo( DeliveryAndPickupAddress::class, 'delivery_area' );
    }
}
