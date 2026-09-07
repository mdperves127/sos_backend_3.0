<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPreOrder extends Model
{
    protected $table = 'product_pre_orders';

    protected $guarded = [];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'quantity_limit'         => 'integer',
        'quantity_ordered'       => 'integer',
        'advance_amount'         => 'float',
    ];

    protected $appends = [
        'remaining_quantity',
        'is_available',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'product_id' );
    }

    public function getRemainingQuantityAttribute(): int
    {
        return max( 0, (int) $this->quantity_limit - (int) $this->quantity_ordered );
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->status === 'active' && $this->remaining_quantity > 0;
    }
}
