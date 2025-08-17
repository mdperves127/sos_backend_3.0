<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierReturnProduct extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    public function color()
    {
        return $this->belongsTo(Color::class, 'r_color_id')->select('id', 'name');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id')->select('id', 'name');
    }

    public function size()
    {
        return $this->belongsTo(Size::class, 'r_size_id')->select('id', 'name');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'r_unit_id')->select('id', 'unit_name');
    }
}
