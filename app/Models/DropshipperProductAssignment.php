<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DropshipperProductAssignment extends Model {

    public const TYPE_PRODUCT  = 'product';
    public const TYPE_CATEGORY = 'category';

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $connection = 'tenant';
    protected $table      = 'dropshipper_product_assignments';

    protected $guarded = [];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public static function targetKey( string $type, int $id ): string {
        return $type . ':' . $id;
    }

    public function product() {
        return $this->belongsTo( Product::class, 'product_id' );
    }

    public function category() {
        return $this->belongsTo( Category::class, 'category_id' );
    }

    public function scopeActive( $query ) {
        return $query->where( 'status', self::STATUS_ACTIVE );
    }
}
