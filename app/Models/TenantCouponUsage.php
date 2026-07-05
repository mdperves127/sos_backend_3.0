<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantCouponUsage extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function coupon()
    {
        return $this->belongsTo( TenantCoupon::class, 'tenant_coupon_id' );
    }

    public function order()
    {
        return $this->belongsTo( Order::class );
    }

    public function user()
    {
        return $this->belongsTo( User::class );
    }
}
