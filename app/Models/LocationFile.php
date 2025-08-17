<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LocationFile extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];


    public function adminAdvertise()
    {
        return $this->hasMany(AdminAdvertise::class);
    }
}
