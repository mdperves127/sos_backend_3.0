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

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'data' => 'array',
    ];

    /**
     * Explicitly define fillable attributes to ensure they're saved as columns, not in data JSON
     */

    protected $fillable = [
        'id',
        'company_name',
        'email',
        'owner_name',
        'phone',
        'address',
        'type',
        'balance'
    ];

    /**
     * Override getCustomColumns to tell VirtualColumn which attributes are actual columns
     * All attributes NOT in this list will be stored in the data JSON column
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'company_name',
            'email',
            'owner_name',
            'phone',
            'address',
            'type',
            'balance',
            'data',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * Get the domains for this tenant
     */
    public function domains()
    {
        return $this->hasMany(Domain::class);
    }

    // The database() method is provided by the HasDatabase trait
    // Custom database configuration can be done in the database() method if needed

    function paymenthistories() {
        return $this->hasMany( PaymentHistory::class, 'tenant_id' );
    }

    /**
     * Convert the model instance to an array, ensuring type comes from the column, not data JSON
     */
    public function toArray()
    {
        $array = parent::toArray();

        // Ensure type comes from the actual column, not from data JSON
        $array['type'] = $this->attributes['type'] ?? null;

        return $array;
    }
}
