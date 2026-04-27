<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentHistory extends Model
{
    use HasFactory,SoftDeletes;

    protected $connection = 'mysql';
    protected $guarded = [];

    public function user() {
        return $this->belongsTo( User::class, 'user_id', 'id' );
    }

    public function tenant() {
        return $this->belongsTo( Tenant::class, 'tenant_id', 'id' );
    }
}
