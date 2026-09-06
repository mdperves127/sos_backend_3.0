<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail for merchant order edits.
 * Do not update or delete rows from application code.
 */
class OrderEditHistory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo( User::class, 'user_id' );
    }

    public function delete()
    {
        throw new \RuntimeException( 'Order edit history is immutable and cannot be deleted.' );
    }

    public function forceDelete()
    {
        throw new \RuntimeException( 'Order edit history is immutable and cannot be deleted.' );
    }
}
