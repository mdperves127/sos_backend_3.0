<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartDetails extends Model {
    use HasFactory;
    protected $guarded = [];

    public function size() {
        return $this->belongsTo( Size::class, 'size' )->select( 'id', 'name' );
    }

    public function unit() {
        return $this->belongsTo( Unit::class, 'unit_id' )->select( 'id', 'unit_name' );
    }

    public function color() {
        return $this->belongsTo( Color::class, 'color' )->select( 'id', 'name' );
    }
}
