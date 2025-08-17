<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosSalesDetails extends Model {
    use HasFactory;

    protected $guarded = [];

    public function product() {
        return $this->belongsTo( Product::class, 'product_id' )->select( 'id', 'name' );
    }

    public function size() {
        return $this->belongsTo( Size::class, 'size_id' )->select( 'id', 'name' );
    }

    public function unit() {
        return $this->belongsTo( Unit::class, 'unit_id' )->select( 'id', 'unit_name' );
    }

    public function color() {
        return $this->belongsTo( Color::class, 'color_id' )->select( 'id', 'name' );
    }

    public function posSale() {
        return $this->belongsTo( PosSales::class, 'id' );
    }
}
