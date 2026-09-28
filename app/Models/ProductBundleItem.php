<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleItem extends Model
{
    protected $connection = 'tenant';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo( ProductBundle::class, 'bundle_id' );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class, 'product_id' );
    }
}
