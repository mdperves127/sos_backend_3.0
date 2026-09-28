<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPayment extends Model
{
    protected $table = 'installment_payments';

    protected $guarded = [];

    protected $casts = [
        'amount'  => 'float',
        'paid_at' => 'datetime',
    ];

    public function installment(): BelongsTo
    {
        return $this->belongsTo( Installment::class, 'installment_id' );
    }

    public function posSale(): BelongsTo
    {
        return $this->belongsTo( PosSales::class, 'pos_sales_id' );
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo( PaymentMethod::class, 'payment_method_id' );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo( User::class, 'user_id' );
    }

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo( CustomerPayment::class, 'customer_payment_id' );
    }
}
