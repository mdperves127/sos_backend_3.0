<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosSales extends Model {
    use HasFactory;

    protected $guarded = [];

    function saleDetails() {
        return $this->hasMany( PosSalesDetails::class );
    }

    public function customer() {
        return $this->belongsTo( Customer::class );
    }

    function returnDetails() {
        return $this->hasMany( PosSaleReturn::class );
    }

    function product() {
        return $this->belongsTo( Product::class );
    }

    function source() {
        return $this->belongsTo( SaleOrderResource::class )->select( 'id', 'name', 'image' );
    }

    function wastageDetails() {
        return $this->hasMany( PosSaleWastageReturn::class );
    }

    function user() {
        return $this->belongsTo( User::class );
    }
}
