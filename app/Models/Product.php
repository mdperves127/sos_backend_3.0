<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model {
    use HasFactory;

    protected $guarded = [];

    public function category() {
        return $this->belongsTo( Category::class, 'category_id', 'id' );
    }

    public function subcategory() {
        return $this->belongsTo( Subcategory::class, 'subcategory_id', 'id' );
    }

    public function brand() {
        return $this->belongsTo( Brand::class, 'brand_id', 'id' );
    }

    public function colors() {
        return $this->belongsToMany( 'App\Models\Color', 'color_product', 'product_id', 'color_id' )->withPivot( 'qnt' );
    }

    public function sizes() {
        return $this->belongsToMany( 'App\Models\Size' )->withTimestamps();
    }

    public function productImage() {
        return $this->hasMany( 'App\Models\ProductImage' );
    }

    public function productdetails() {
        return $this->hasMany( 'App\Models\ProductDetails' );
    }

    public function vendor() {
        return $this->belongsTo( User::class, 'user_id', 'id' );
    }

    function specifications() {
        return $this->hasMany( specification::class, 'product_id', 'id' );
    }

    function orders() {
        return $this->hasMany( Order::class, 'product_id', 'id' );
    }

    function pendingproduct() {
        return $this->hasOne( PendingProduct::class, 'product_id' );
    }

    function productrating() {
        return $this->hasMany( ProductRating::class, 'product_id' );
    }

    protected $casts = [
        'tags'            => 'array',
        'variants'        => 'array',
        'meta_keyword'    => 'array',
        'selling_details' => 'array',
        'specifications'  => 'array',
    ];

    function scopeSearch( $query, $value ) {
        $query->where( 'name', 'like', "%{$value}%" )
            ->orWhere( 'uniqid', $value );
    }

    function productVariant() {
        return $this->hasMany( 'App\Models\ProductVariant' );
    }

    function posSaleDetails() {
        return $this->hasMany( PosSalesDetails::class );
    }

    function purchaseDetails() {
        return $this->hasMany( ProductPurchaseDetails::class );
    }

    function supplier() {
        return $this->belongsTo( Supplier::class );
    }

    function warehouse() {
        return $this->belongsTo( Warehouse::class );
    }

    public function product_details() {
        return $this->hasMany( ProductDetails::class );
    }

    public function orderDetails() {
        return $this->hasMany( OrderDetails::class );
    }
}
