<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Placement extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    function category(){
        return $this->belongsTo(CampaignCategory::class,'campaign_category_id');
    }
}
