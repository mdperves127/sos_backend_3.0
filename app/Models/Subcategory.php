<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subcategory extends Model
{
    use HasFactory;

    protected $table = 'subcategories';



    //   protected $with = ['category'];
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }
}
