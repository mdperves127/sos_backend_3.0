<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderDeliveryToCourier extends Model {
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function courierCredential() {
        return $this->belongsTo( CourierCredential::class, 'courier_id' );
    }
}
