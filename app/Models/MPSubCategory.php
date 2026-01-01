<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MPSubCategory extends Model
{
    use HasFactory,SoftDeletes;
    protected $connection = 'mysql';

    protected $guarded = [];


    public function category()
    {
        return $this->belongsTo(MPCategory::class, 'category_id', 'id');
    }
}
