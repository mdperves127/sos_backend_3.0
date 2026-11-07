<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShippingBoxSize extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'shipping_box_sizes';

    protected $fillable = [
        'name',
        'length',
        'width',
        'height',
        'measurement_type',
        'weight',
        'weight_type',
        'status',
    ];

    protected $casts = [
        'length' => 'float',
        'width'  => 'float',
        'height' => 'float',
        'weight' => 'float',
    ];
}
