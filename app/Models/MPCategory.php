<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MPCategory extends Model
{
    use HasFactory,SoftDeletes;
    protected $connection = 'mysql';

    protected $guarded = [];


    public function subcategory()
    {
        return $this->hasMany('App\Models\MPSubCategory');
    }
}
