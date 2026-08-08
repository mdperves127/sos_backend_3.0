<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantMaterial extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $guarded = [];

    public static function singleton(): self
    {
        return static::query()->firstOrCreate( ['id' => 1] );
    }
}
