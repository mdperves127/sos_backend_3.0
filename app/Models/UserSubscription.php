<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class UserSubscription extends Model {
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $connection = 'mysql';

    function subscription() {
        return $this->belongsTo( Subscription::class, 'subscription_id' );
    }

    function user() {
        return $this->belongsTo( User::class );
    }

    function product_details() {

        if ( Auth::user()->role_as == 2 ) {
            return $this->hasOne( ProductDetails::class, 'user_id', 'user_id' )->where( 'status', 1 );
        } elseif ( Auth::user()->role_as == 3 ) {
            return $this->hasOne( ProductDetails::class, 'vendor_id', 'user_id' )->where( 'status', 1 );
        } else {
            return null;
        }
    }
}
