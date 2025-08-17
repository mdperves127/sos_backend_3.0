<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceCategory extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];
    function servicesubCategories(){
        return $this->hasMany(ServiceSubCategory::class,'service_category_id');
    }
}
