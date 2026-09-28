<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSaleDue extends Model
{
    protected $table = 'pos_sale_dues';

    protected $guarded = [];

    protected $casts = [
        'due_date'               => 'date',
        'last_due_reminder_date' => 'date',
    ];

    public function posSale(): BelongsTo
    {
        return $this->belongsTo( PosSales::class, 'pos_sales_id' );
    }
}
