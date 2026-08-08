<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantVerification extends Model
{
    protected $connection = 'mysql';

    protected $guarded = [];

    protected $casts = [
        'email_verify_code_at' => 'datetime',
        'email_verified_at'    => 'datetime',
        'phone_verify_code_at' => 'datetime',
        'phone_verified_at'    => 'datetime',
    ];
}
