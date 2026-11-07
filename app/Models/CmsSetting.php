<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsSetting extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [];

    protected $hidden = [
        'header_footer',
    ];

    protected $casts = [
        'header_footer' => 'array',
    ];
}
