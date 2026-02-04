<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NCategory extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    protected $table = 'n_categories';

    public function news()
    {
        return $this->hasMany(News::class, 'n_category_id', 'id');
    }
}
