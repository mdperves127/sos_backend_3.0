<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function sub_unit()
    {
        return $this->hasMany(SubUnit::class);
    }
}
