<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorService extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];
    protected $casts = [
        'tags' => 'json'
    ];
    function servicepackages()
    {
        return $this->hasMany(ServicePackage::class);
    }

    function firstpackage()
    {
        return $this->hasOne(ServicePackage::class,'vendor_service_id');
    }

    function serviceimages()
    {
        return $this->hasMany(ServiceImages::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleted(function ($query) {
            $query->servicepackages()->delete();
            $query->serviceimages()->delete();
        });
    }

    function user(){
        return $this->belongsTo(User::class);
    }

    function serviceorders(){
        return $this->hasMany(ServiceOrder::class,'vendor_service_id');
    }

    function servicerating(){
        return $this->hasMany(ServiceRating::class,'vendor_service_id');
    }

    function servicecategory(){
        return $this->belongsTo(ServiceCategory::class,'service_category_id');
    }

    function servicesubcategory(){
        return $this->belongsTo(ServiceSubCategory::class,'service_sub_category_id');
    }

    function serviceratings(){
        return $this->hasMany(ServiceRating::class,' vendor_service_id');
    }
}
