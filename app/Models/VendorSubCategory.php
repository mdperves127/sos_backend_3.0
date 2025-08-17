<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorSubCategory extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function VendorCategory(){
        return $this->belongsTo(VendorCategory::class );
    }
}
