<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantInstalledAddon extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'tenant_addons';

    protected $guarded = [];

    protected $casts = [
        'price_paid'   => 'decimal:2',
        'activated_at' => 'datetime',
    ];

    public function addon(): BelongsTo
    {
        return $this->belongsTo( Addon::class );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo( Tenant::class );
    }
}
