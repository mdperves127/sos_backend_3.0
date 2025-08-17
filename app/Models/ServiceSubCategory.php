<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class ServiceSubCategory extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    function serviceCategory()
    {
        return $this->belongsTo(ServiceCategory::class);
    }
}
