<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VariantAttributeValue extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'variant_attribute_id',
        'value',
        'sort_order',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo( VariantAttribute::class, 'variant_attribute_id' );
    }
}
