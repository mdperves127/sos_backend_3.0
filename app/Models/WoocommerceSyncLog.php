<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WoocommerceSyncLog extends Model {
    protected $connection = 'tenant';

    protected $table = 'woocommerce_sync_logs';

    protected $guarded = [];

    protected $casts = [
        'payload_before' => 'array',
        'payload_after'  => 'array',
        'retry_count'    => 'integer',
    ];

    public function credential() {
        return $this->belongsTo( WoocommerceCredential::class, 'credential_id' );
    }
}
