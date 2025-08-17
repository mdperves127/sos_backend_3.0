<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportBoxCategory extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    function problems(){
        return $this->hasMany(SupportProblemTopic::class,'support_box_category_id');
    }
}
