<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosSales extends Model {
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'paid_amount' => 'float',
        'due_amount'  => 'float',
        'total_price' => 'float',
    ];

    protected $appends = [
        'due_status',
    ];

    function saleDetails() {
        return $this->hasMany( PosSalesDetails::class );
    }

    public function customer() {
        return $this->belongsTo( Customer::class );
    }

    public function payments() {
        return $this->hasMany( CustomerPayment::class, 'pos_sales_id' )->latest( 'id' );
    }

    public function dueRecord() {
        return $this->hasOne( PosSaleDue::class, 'pos_sales_id' );
    }

    public function installments() {
        return $this->hasMany( Installment::class, 'pos_sales_id' )->orderBy( 'installment_number' );
    }

    public function isInstallmentOrder(): bool
    {
        if ( $this->relationLoaded( 'installments' ) ) {
            return $this->installments->isNotEmpty();
        }

        return $this->installments()->exists();
    }

    function returnDetails() {
        return $this->hasMany( PosSaleReturn::class, 'pos_sales_id' );
    }

    function exchangeDetails() {
        return $this->hasMany( ExchangeSaleProduct::class, 'pos_sales_id' );
    }

    function product() {
        return $this->belongsTo( Product::class );
    }

    function source() {
        return $this->belongsTo( SaleOrderResource::class )->select( 'id', 'name', 'image' );
    }

    function wastageDetails() {
        return $this->hasMany( PosSaleWastageReturn::class );
    }

    function user() {
        return $this->belongsTo( User::class );
    }

    /**
     * paid | partial | overdue
     * For installment orders, status is derived from installment remaining/due dates.
     */
    public function getDueStatusAttribute(): string
    {
        $due = (float) ( $this->due_amount ?? 0 );

        if ( $due <= 0 || ( $this->payment_status ?? null ) === 'paid' ) {
            return 'paid';
        }

        if ( $this->isInstallmentOrder() ) {
            $hasOverdue = $this->installments()
                ->where( 'remaining_amount', '>', 0 )
                ->whereDate( 'due_date', '<', Carbon::today()->toDateString() )
                ->exists();

            return $hasOverdue ? 'overdue' : 'partial';
        }

        $dueDateValue = $this->dueRecord?->due_date;

        if ( $dueDateValue ) {
            $dueDate = $dueDateValue instanceof Carbon
                ? $dueDateValue->copy()->startOfDay()
                : Carbon::parse( $dueDateValue )->startOfDay();

            if ( $dueDate->lt( Carbon::today() ) ) {
                return 'overdue';
            }
        }

        return 'partial';
    }
}
