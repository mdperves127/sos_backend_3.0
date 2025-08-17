<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductPurchase extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    function purchaseDetails(){
        return $this->hasMany(ProductPurchaseDetails::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    function returnDetails(){
        return $this->hasMany(SupplierReturnProduct::class);
    }

    function product(){
        return $this->belongsTo(Product::class);
    }
}
