<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Color extends Model
{
    use HasFactory;

    public function products()
    {
        return $this->belongsToMany('App\Models\Product')->withTimestamps();
    }


    public function carts()
    {
        return $this->belongsToMany('App\Models\Cart')->withTimestamps();
    }

}
