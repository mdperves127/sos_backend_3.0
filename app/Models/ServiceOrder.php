<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceOrder extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    function customerdetails(){
        return $this->belongsTo(User::class,'user_id');
    }

    function servicedetails(){
        return $this->belongsTo(VendorService::class,'vendor_service_id');
    }

    function packagedetails(){
        return $this->belongsTo(ServicePackage::class,'service_package_id');
    }
    function requirementsfiles(){
        return $this->hasMany(CustomerRequiremnt::class,'vendor_service_id');
    }

    function orderdelivery(){
        return $this->hasMany(OrderDelivery::class,'service_order_id');
    }
    function vendor(){
        return $this->belongsTo(User::class,'vendor_id');
    }

    function files(){
        return $this->morphMany(File::class,'filetable');
    }

    function servicerating(){
        return $this->hasOne(ServiceRating::class,'service_order_id');
    }
}
