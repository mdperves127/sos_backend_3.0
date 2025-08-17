<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Size extends Model
{
    use HasFactory;

    public function products()
    {
        return $this->belongsToMany('App\Models\Product')->withTimestamps();
    }
}
