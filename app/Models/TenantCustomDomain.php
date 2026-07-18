<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCustomDomain extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'tenant_id',
        'domain',
        'status',
        'verification',
        'ssl',
        'target_ip',
        'last_dns_check',
        'verified_at',
        'activated_at',
    ];

    protected $casts = [
        'last_dns_check' => 'array',
        'verified_at'      => 'datetime',
        'activated_at'     => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
