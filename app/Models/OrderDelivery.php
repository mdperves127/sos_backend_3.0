<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderDelivery extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    function deliveryfiles(){
        return $this->hasMany(DeliveryFile::class);
    }
}
