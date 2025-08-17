<?php

namespace App\Models;

use App\Traits\FilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CouponRequest extends Model
{
    use HasFactory,SoftDeletes, FilterTrait;

    protected $guarded = [];

    function user(){
        return $this->belongsTo(User::class,'user_id');
    }

    protected $searchables = ['user.email'];

}
