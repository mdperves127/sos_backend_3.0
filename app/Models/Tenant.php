<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Concerns\HasDatabase;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasFactory, SoftDeletes, HasDatabase;

    protected $guarded = [];

    /**
     * Get the domains for this tenant
     */
    public function domains()
    {
        return $this->hasMany(Domain::class);
    }

    // The database() method is provided by the HasDatabase trait
    // Custom database configuration can be done in the database() method if needed
}
