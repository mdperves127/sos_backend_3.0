<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model {
    use HasFactory;

    protected $connection = 'tenant';
    protected $table = 'carts';
    protected $guarded = [];

    public function product() {
        return $this->belongsTo( 'App\Models\Product' );
    }

    public function colors() {
        return $this->belongsToMany( 'App\Models\Color' )->withTimestamps();
    }

    public function sizes() {
        return $this->belongsToMany( 'App\Models\Size' )->withTimestamps();
    }

    public function unit() {
        return $this->belongsToMany( 'App\Models\Unit' )->withTimestamps();
    }

    function cartDetails() {
        return $this->hasMany( CartDetails::class, 'cart_id', 'id' )->with( 'color', 'size', 'unit' );
    }

}
