<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'heading',
        'description',
        'created_at',
        'updated_at',
        'deleted_at',
    ];



    protected $table = 'categories';

    public function subcategory()
    {
        return $this->hasMany('App\Models\Subcategory');
    }

    function products(){
        return $this->hasMany(Product::class,'category_id','id');
    }

    function order(){
        return $this->hasMany(Order::class,'category_id','id');
    }
}
