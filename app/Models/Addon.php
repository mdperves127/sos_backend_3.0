<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Addon extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mysql';

    protected $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function installations(): HasMany
    {
        return $this->hasMany( TenantInstalledAddon::class );
    }
}
