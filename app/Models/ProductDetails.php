<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDetails extends Model
{
    use HasFactory ;

    protected $guarded = [];
    protected $connection = 'tenant';
    protected $table = 'product_details';

    public function product(){
        return $this->belongsTo('App\Models\Product');
    }

    function affiliator(){
        return $this->belongsTo(User::class,'user_id','id');
    }

    function vendor(){
        return $this->belongsTo(User::class,'vendor_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }


}
