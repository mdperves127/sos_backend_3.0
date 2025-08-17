<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Barcode extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    function productVariant()
    {
        return $this->hasOne(ProductVariant::class,'id','variant_id')->with('product','color','size','unit');
    }
}
