<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installment extends Model
{
    protected $table = 'installments';

    protected $guarded = [];

    protected $casts = [
        'amount'           => 'float',
        'paid_amount'      => 'float',
        'remaining_amount' => 'float',
        'due_date'         => 'date',
        'last_reminder_date' => 'date',
    ];

    protected $appends = [
        'display_status',
    ];

    public function posSale(): BelongsTo
    {
        return $this->belongsTo( PosSales::class, 'pos_sales_id' );
    }

    public function payments(): HasMany
    {
        return $this->hasMany( InstallmentPayment::class, 'installment_id' )->latest( 'id' );
    }

    /**
     * unpaid | partial | paid | overdue
     */
    public function getDisplayStatusAttribute(): string
    {
        if ( (float) $this->remaining_amount <= 0 || $this->status === 'paid' ) {
            return 'paid';
        }

        $dueDate = $this->due_date instanceof Carbon
            ? $this->due_date->copy()->startOfDay()
            : Carbon::parse( $this->due_date )->startOfDay();

        if ( $dueDate->lt( Carbon::today() ) ) {
            return 'overdue';
        }

        return (float) $this->paid_amount > 0 ? 'partial' : 'unpaid';
    }

    public function syncAmountsAndStatus(): void
    {
        $amount    = round( (float) $this->amount, 2 );
        $paid      = round( (float) $this->paid_amount, 2 );
        $remaining = max( 0, round( $amount - $paid, 2 ) );

        $this->paid_amount      = $paid;
        $this->remaining_amount = $remaining;

        if ( $remaining <= 0 ) {
            $this->status      = 'paid';
            $this->paid_amount = $amount;
            $this->remaining_amount = 0;
        } elseif ( $paid > 0 ) {
            $this->status = 'partial';
        } else {
            $this->status = 'unpaid';
        }

        $this->save();
    }
}
