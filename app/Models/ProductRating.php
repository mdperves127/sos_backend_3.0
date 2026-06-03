<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductRating extends Model {
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function scopeVisibleOnFrontend( $query ) {
        return $query->where( 'is_visible', true );
    }

    function affiliate() {
        return $this->belongsTo( User::class, 'user_id' );
    }

    function user() {
        return $this->belongsTo( User::class, 'user_id' );
    }

    function product() {
        return $this->belongsTo( Product::class );
    }

    function order() {
        return $this->belongsTo( Order::class );
    }

}
