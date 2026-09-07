<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBundle extends Model
{
    protected $connection = 'tenant';

    protected $guarded = [];

    protected $casts = [
        'bundle_price' => 'float',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo( Category::class, 'category_id' );
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo( Subcategory::class, 'subcategory_id' );
    }

    public function items(): HasMany
    {
        return $this->hasMany( ProductBundleItem::class, 'bundle_id' );
    }

    public function scopeActive( $query )
    {
        return $query->where( 'status', 'active' );
    }

    /**
     * Sum of current component product prices (discount_price ?? selling_price) × qty.
     */
    public function calculateRegularPrice(): float
    {
        $total = 0.0;

        foreach ( $this->items as $item ) {
            $product = $item->product;
            if ( ! $product ) {
                continue;
            }
            $unit = (float) ( $product->discount_price ?: $product->selling_price ?: 0 );
            $total += $unit * max( 1, (int) $item->quantity );
        }

        return round( $total, 2 );
    }
}
